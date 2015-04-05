<?php

App::uses('AppController', 'Controller');
App::uses('Gravatar', 'Lib');
App::uses('Account', 'Lib');

class OrganizationsController extends AppController {

    public $uses = array('Organization');
    public $components = array('HookDelegator', 'GAcl.UserManagement', 'GAcl.GAcl');

    public function beforeFilter() {
        parent::beforeFilter();
        $this->Auth->allow('create');
    }

    public function home($organization_id) {
        //$this->GAcl->check('event_details', ACL_ACTION_READ);

        if ($this->request->query('sync')) {
            $this->resyncSession();
        }

        $organization = $this->Organization->find('first', array(
            'conditions' => array('Organization.id' => $organization_id),
            'recursive' => -1
        ));

        $this->set('organization', $organization);
        $this->setTitle($organization['Organization']['name'] . "'s events");

        //See if they have event or not. If not, render the blank event
        if ($this->Organization->hasEvent($organization['Organization']['id'])) {
            
        } else {
            $this->render('startup');
        }
    }

    public function feeds($organization_id) {
        $last_feed_id = $this->request->query('last_feed_id');
        $feeds = $this->Organization->getLatestUpdates($this->Auth->user('id'), $organization_id, $last_feed_id);
        $this->responseSuccess($feeds);
    }

    public function update($organization_id) {
        $this->GAcl->check('admin', ACL_ACTION_UPDATE);
        //Currently only update these info
        $data = array_intersect_key($this->data, array_flip(array('name', 'timezone_id', 'currency_id', 'color', 'password_expiration')));
        $data['id'] = $organization_id;
        if ($data['password_expiration'] == 0) {
            $data['password_expiration'] = NULL;
        }

        $this->Organization->save($data);

        //Delegate update call
        $organization = $this->Organization->findById($organization_id);
        $this->HookDelegator->delegate('didUpdateOrganization', $organization['Organization']);

        //Then update cache once
        $this->resyncSession();

        $this->responseSuccess();
    }

    /**
     * Fetch all users under this organization
     */
    public function users($organization_id) {
        $users = $this->Organization->getUsers($organization_id);

        //Calculate avatar
        foreach ($users as &$u) {
            $u['User']['avatar'] = !empty($u['User']['avatar']) ? $u['User']['avatar'] : Gravatar::Avatar($u['User']['email'], 16);
            $u['User']['type'] = 'User'; //for jqueryMentionInput
        }
        unset($u);

        $this->responseSuccess(Hash::extract($users, '{n}.User'));
    }

    private function createOrganization($name) {
        $mysql = $this->Organization->getDataSource();

        $mysql->begin();

        $this->Organization->create(false);
        if ($organization = $this->Organization->save(array(
            'name' => $name
                ))) {

            $this->Organization->addUser($this->Auth->user('id'), $organization['Organization']['id'], array(
                'create_event' => true,
                'admin' => true,
                'access_contact' => true,
                'owner' => true
            ));

            $this->HookDelegator->delegate('didCreateOrganization', $organization['Organization'], array(
                'request' => $this->data,
                'user' => $this->Auth->user()
            ));

            //Add predefined roles to organization
            $roles = $this->UserManagement->addPredefinedRolesToOrg($organization['Organization']);
            $this->UserManagement->addUserToRole($organization['Organization'], $this->Auth->user(), $roles['admin']);

            $mysql->commit();

            return $organization;
        } else {
            $mysql->rollback();
            return false;
        }
    }

    public function create() {

        throw new \NotFoundException();

        if ($this->Auth->user('id')) {
            $orgs = $this->Organization->User->getOrganizations($this->Auth->user('id'));

            if (empty($orgs)) {
                $this->layout = 'basic_white';
            }

            if ($this->request->is('post')) {
                if ($organization = $this->createOrganization($this->request->data['Organization']['name'])) {
                    $this->resyncSession();
                    $this->redirect(array(
                        'controller' => 'Organizations',
                        'action' => 'home',
                        'organization_id' => $organization['Organization']['id']
                    ));
                } else {
                    $this->flashWarning($this->Organization->validationErrorsAsText());
                }
            }
        } else if ($this->Session->check('Weak')) {
            //weak org create
            $this->layout = 'basic_white';
            $this->view = 'create_weak';
            $this->Session->delete('Message');
            $this->set('account', $this->Session->read('Weak.Account'));
            $this->set('user', $this->Session->read('Weak.User'));

            if ($this->request->is('post')) {

                $account = $this->Session->read('Weak.Account');
                $user = $this->Session->read('Weak.User');
                $password = trim($this->data['User']['password']);
                $orgname = trim($this->data['Organization']['name']);


                if (!empty($orgname)) {
                    $authAccount = Account::Authenticate($account['username'], $password);
                    if (!empty($authAccount)) {

                        //Account authenticated, we can proceed to create org, plus update the user's first/last/company info
                        $clean_weak_data = array_intersect_key($account['weak'], array_flip(array('firstname', 'lastname', 'company')));
                        $clean_weak_data['company'] = $orgname;
                        Account::Update($authAccount['id'], $authAccount['session'], $clean_weak_data);

                        //And then let's create org
                        //Also merge latest data in
                        $authAccount = array_merge($authAccount, $clean_weak_data);

                        //If it's existing user
                        if (!empty($user)) {
                            $processedUser = $this->Organization->User->processAccount($authAccount, false); //don't auto create, it's weak user
                            //If we do not have any user related to this account
                        } else {
                            $processedUser = $this->Organization->User->processAccount($authAccount); //auto create for weak user that do not have account linked
                        }

                        if (!empty($processedUser)) {
                            $this->Auth->login($processedUser['User']);
                            if ($organization = $this->createOrganization($this->request->data['Organization']['name'])) {
                                $this->resyncSession();
                                $this->Session->delete('Weak');
                                $this->redirect(array(
                                    'controller' => 'Organizations',
                                    'action' => 'home',
                                    'organization_id' => $organization['Organization']['id']
                                ));
                            } else {
                                $this->Session->delete('Auth');
                                $this->flashWarning($this->Organization->validationErrorsAsText());
                            }
                        } else {
                            $this->flashError('Failed to relate weak user to an existing user', 'auth');
                        }
                    } else {
                        $this->flashError('Login failed. Try again?', 'auth');
                    }
                } else {
                    $this->flashError('Organization name shouldn\'t be empty', 'auth');
                }
            }
        } else {
            $this->redirect('/');
        }

        $this->set('title_for_layout', 'Create Organization');
    }

    private function resyncSession() {
//		$this->Session->write('Auth.User.organizations', $this->Organization->User->getOrganizations($this->Auth->user('id'), $this->Auth->user('admin')));
//		$this->Session->write('Auth.User.permissions', $this->Organization->User->getPermissionMap($this->Auth->user('id')));
    }

    //Get events
    public function events($organization_id) {
        $events = $this->Organization->OrganizationsUser->User->getEvents($this->Auth->user('id'), $organization_id, array(
            'fields' => array(
                'Event.id', 'Event.organization_id', 'Event.name',
                'Event.base_color', 'Event.published', 'Event.archived', 'Event.cancelled', 'Event.publish_begin',
                'Event.timezone_id', 'Event.timezone', 'Event.attendee_count', 'Event.events_user_count', 'Event.last_action', 'Event.pending_status'
            ),
            'order' => 'name ASC'
        ));

        $fields = array('id', 'organization_id', 'name', 'base_color',
            'published', 'archived', 'timezone_id', 'timezone', 'cancelled', 'publish_begin',
            'attendees', 'people', 'last_action', 'pending_status', 'collaborating');
        if (isset($this->request->query['fast'])) {
            $fields = array('id', 'name', 'organization_id', 'base_color', 'archived', 'cancelled');
        }

        foreach ($events as &$event) {
            $event['people'] = $event['events_user_count'];
            $event['attendees'] = $event['attendee_count'];
            $event['collaborating'] = $this->getAllCollaboratingUsers($event['organization_id'], $event['id'], 'all_event');

            $event = array_intersect_key($event, array_flip($fields));
        }
        unset($event);
        $this->responseSuccess($events);
    }

    private function getAllCollaboratingUsers($organization_id, $event_id, $aco) {
        $User = ClassRegistry::init('User');
        $EventsUser = ClassRegistry::init('EventsUser');

        $user_ids = $this->GAcl->getAcoUsers($aco, $organization_id);
        $event_users = $EventsUser->find('all', array(
            'recursive' => -1,
            'fields' => array('user_id'),
            'conditions' => array(
                'event_id' => $event_id
            )
        ));

        $eventUserIds = Hash::extract($event_users, '{n}.' . $EventsUser->alias . '.user_id');
        /// get final user count;
        $users = $User->find('count', array(
            'recursive' => -1,
            'fields' => array(
                'id', 'firstname', 'lastname', 'email'
            ),
            'conditions' => array(
                'id' => array_unique(array_merge($user_ids, $eventUserIds))
            )
        ));
        return $users;
    }

    /**
     * autocomplete search users in an organization, pass in event_id to
     * filter users who are NOT IN event_id
     */
    public function users_search($organization_id) {

        App::uses('Sanitize', 'Utility');

        $query = Sanitize::clean($this->request->query('q'));


//		//Get all the events that this user has access to, under this organization
//		$events = $this->Organization->Event->EventsUser->find('all', array(
//			'conditions' => array(
//				'EventsUser.user_id' => $this->Auth->user('id'),
//				'EventsUser.organization_id' => $organization_id
//			),
//			'recursive' => -1,
//			'fields' => array('EventsUser.event_id'),
//			'contain' => array('Event' => array('id'))
//		));
//		
//		$event_ids = Hash::extract($events, '{n}.Event.id');
        //Get all the user ids who matched, and under this organization
        $users = $this->Organization->User->find('all', array(
            'conditions' => array(
                'OR' => array(
                    'User.firstname LIKE' => '%' . $query . '%',
                    'User.lastname LIKE' => '%' . $query . '%',
                    'User.email LIKE' => '%' . $query . '%'
                ),
                'OrganizationsUser.organization_id' => $organization_id,
                'OrganizationsUser.user_id = User.id'
            ),
            'joins' => array(
                array(
                    'table' => 'organizations_users',
                    'alias' => 'OrganizationsUser',
                    'type' => 'INNER',
                    'conditions' => array(
                        'OrganizationsUser.user_id = User.id'
                    )
                )
            ),
            'limit' => 6,
            'fields' => array(
                'id', 'firstname', 'lastname', 'email', 'avatar'
            ),
            'recursive' => -1
        ));
        $final_users = Hash::extract($users, '{n}.User');


//		
//		//Then we filter users in all the events that this user has access
//		//to, so they can't dig who's in the organization but not appearing
//		$filteredUsers = $this->Organization->Event->EventsUser->find('all', array(
//			'conditions' => array(
//				'EventsUser.user_id' => $user_ids,
//				'EventsUser.event_id' => $event_ids,
//				'EventsUser.organization_id' => $organization_id
//			),
//			'recursive' => -1,
//			'fields' => array('EventsUser.event_id'),
//			'contain' => array('User' => array('id', 'firstname', 'lastname', 'email'))
//		));
//		
//		debug($filteredUsers);
//		exit;
        //Calculate avatar
        foreach ($final_users as &$u) {
            $u['avatar'] = !empty($u['avatar']) ? $u['avatar'] : Gravatar::Avatar($u['email'], 24);
        }
        unset($u);

        $this->responseSuccess($final_users);
    }

    public function payouts($organization_id) {
        $PayoutAccount = ClassRegistry::init('PayoutAccount');
        return $this->responseSuccess($PayoutAccount->getPayoutAccountsOfOrganization($organization_id));
    }

    public function getMetaData($organization_id) {
        $injectionPoint = $this->request->data('injectionPoint');
        $schema = $this->Organization->getMetaSchema($organization_id, $injectionPoint);
        $this->responseSuccess(array(
            'schema' => !empty($schema) ? $schema['fields'] : array(),
            'meta' => array()
        ));
    }

    /**
     * load expired subscription screen for non-owners
     */
    public function trialExpired($organization_id) {

        $CompanyModel = ClassRegistry::init('Company');
        $organization = $this->Organization->findById($organization_id);

        $company = $CompanyModel->find('first', array(
            'recursive' => -1,
            'fields' => array('id', 'name', 'create_organization', 'trial_ended', 'trial_end', 'trial_end_utc'),
            'conditions' => array('id' => $organization['Organization']['company_id'])
        ));
        $company = $company[$CompanyModel->alias];
        $isTrialExpired = $CompanyModel->isTrialExpired($company['id']);
        $return_array = array('company' => $company,
            'trial_expired' => $isTrialExpired,
            'getInTouchUrl' => 'mailto:sales@gevme.com?subject=GEVME%20Subscription%20Enquiry%20(COMPANY/' . $company['id'] . ')');
        $this->set('data', $return_array);
        $this->render('expire');
    }

}
