<?php
App::uses('AppController', 'Controller');
App::uses('Gravatar', 'Lib');
App::uses('Sanitize', 'Utility');

class EventsUsersController extends AppController {

	public $uses = array('EventsUser');
    public $components = array('GAcl.GAcl');
	
	/**
	 * Get users and invitations under this event, used by people
	 * 
	 * @param type $organization_id
	 * @param type $event_id
	 */
	public function people_listing($organization_id, $event_id) {
		
		//Block users who do not have access to this
		//Check if user has correct permission
		if ($this->Auth->user('admin') != 1 && !$this->EventsUser->Event->Organization->isAdmin($this->Auth->user('id'), $organization_id)) {
			$this->responseFail('You cannot access this resources');
			return;
		}
		
		$events_users = $this->EventsUser->find('all', array(
			'conditions'=>array(
				'EventsUser.event_id' => $event_id
			),
			//'fields'=>array('id', 'invited_by', 'modules', 'organization_id'),
			'contain' => array(
				'User' => array(
					'fields' => array('firstname', 'lastname', 'id', 'email', 'avatar'),
					'OrganizationsUser' => array(
						'fields' => array('admin', 'create_event', 'owner', 'access_contact'),
						'conditions' => array('OrganizationsUser.organization_id' => $organization_id)
					)
				)
			)
		));
		
		//rearrange array nicely
		$users = array();
		foreach($events_users as $eu) {
			$users[] = array(
				'id' => $eu['User']['id'],
				'modules' => $eu['EventsUser']['modules'],
				'organization_id' => $organization_id,
				'event_id' => $event_id,
				'avatar' => !empty($eu['User']['avatar']) ? $eu['User']['avatar'] : Gravatar::Avatar($eu['User']['email']),
				'admin' => $eu['User']['OrganizationsUser'][0]['admin'],
				'organization_owner' => $eu['User']['OrganizationsUser'][0]['owner'], 
				'create_event' => $eu['User']['OrganizationsUser'][0]['create_event'],
				'access_contact' => $eu['User']['OrganizationsUser'][0]['access_contact'],
				'firstname' => $eu['User']['firstname'],
				'lastname' => $eu['User']['lastname'],
				'email' => $eu['User']['email'],
				'me' => $eu['User']['id'] == $this->Auth->user('id')
			);
		}
		
		$invitations = $this->EventsUser->Event->EventInvitation->find('all', array(
			'conditions' => array(
				'EventInvitation.event_id' => $event_id,
				'EventInvitation.organization_id' => $organization_id,
				'EventInvitation.accepted' => '0000-00-00 00:00:00',
				'EventInvitation.user_id' => '0'
			),
			'fields' => array('email', 'id', 'modules', 'admin', 'create_event', 'access_contact'),
			'recursive' => -1,
			'order' => array('EventInvitation.created'=>'DESC')
		));
		
		$this->responseSuccess(array(
			'users' => $users,
			'invitations' => Hash::extract($invitations, '{n}.EventInvitation')
		));
	}
	
	/**
	 * Get users under the event. Just names only
	 * 
	 * @param type $organization_id
	 * @param type $event_id
	 */
	public function users_exclude_myself($organization_id, $event_id) {
		$my_id = $this->Auth->user('id');
		$events_users = $this->EventsUser->find('all', array(
			'conditions'=>array(
				'EventsUser.event_id' => $event_id,
				'EventsUser.user_id <>' => $my_id
			),
			'fields'=>array('id', 'invited_by', 'modules', 'organization_id'),
			'contain' => array(
				'User' => array(
					'fields' => array('firstname', 'lastname', 'id')
				)
			)
		));
		$this->responseSuccess(array('users'=>Hash::extract($events_users, '{n}.User')));
	}
	
	/**
	 * Invite users to this event. User will automatically enrolled into the
	 * organization, but granted as normal user
	 * 
	 * Normal user do not have access to create event
	 * 
	 * @param type $organization_id
	 * @param type $event_id
	 */
	public function people($organization_id, $event_id) {
        $this->GAcl->check('admin', ACL_ACTION_READ);
        //if they have admin access, they will get bounced to here:
        $this->redirect("/admin-console#/organisations/$organization_id/users");
        //else, get bounce away
		$this->setTitle('People');
	}
	
	/**
	 * POST to delete user from event
	 */
	public function remove_user($organization_id, $event_id) {
		$delete_user_id = $this->request->data('user_id');
		
		if ($this->Auth->user('admin') || $this->EventsUser->Event->Organization->isAdmin($this->Auth->user('id'), $organization_id)) {
			if ($this->Event->removeUser($delete_user_id, $event_id)) {
				$this->responseSuccess(array(
					'logout' => $this->data['user_id'] == $this->Auth->user('id')
				));
			}else{
				$this->responseFail(__('Illegal request. Failed to remove user'));
			}
		}else{
			$this->responseFail(__('Illegal request. Failed to remove user'));
		}
	}
	
	/**
	 * POST to delete user from event
	 */
	public function update_user($organization_id, $event_id) {
		
		//only admin can update user
		if ($this->Auth->user('admin') || $this->EventsUser->Event->Organization->isAdmin($this->Auth->user('id'), $organization_id)) {
			if ($this->EventsUser->Event->updateUserModules(
					$this->data['user_id'],
					$event_id, $this->data['modules']) &&
				$this->EventsUser->Event->Organization->updateUserPermission(
					$this->data['user_id'],
					$organization_id,
					array(
						'access_contact' => $this->data['access_contact'] === 'true',
						'create_event' => $this->data['create_event'] === 'true',
						'admin' => $this->data['admin'] === 'true'
					))) {
				
				$this->responseSuccess(array(
					'logout' => $this->data['user_id'] == $this->Auth->user('id')
				));
			}else{
				$this->responseFail(__('Failed to update user'));
			}
		}else{
			$this->responseFail(__('Illegal request. Failed to update user'));
		}
	}
	
	/**
	 * autocomplete search users in an organization, pass in event_id to
	 * filter users who are NOT IN event_id
	 */
	public function autocomplete($organization_id, $event_id) {
		
		$query = Sanitize::clean($this->request->query('q'));
		
		$existing_users_in_events = $this->EventsUser->Event->getUsers($event_id);
		$existing_users_in_invitation = $this->EventsUser->Event->EventInvitation->find('all', array(
			'conditions' => array(
				'EventInvitation.event_id' => $event_id,
				'EventInvitation.accepted' => 0,
				'EventInvitation.organization_id' => $organization_id
			),
			'recursive' => -1,
			'fields' => array('EventInvitation.email')
		));
		
		$existing_users_in_invitation_emails = Hash::extract($existing_users_in_invitation, '{n}.EventInvitation.email');
				
		$users_raw = $this->EventsUser->Event->Organization->User->find('all', array(
			'conditions' => array(
				'OR' => array(
					'User.firstname LIKE' => '%'.$query.'%',
					'User.lastname LIKE' => '%'.$query.'%',
					'User.email LIKE' => '%'.$query.'%'
				),
				'OrganizationsUser.organization_id' => $organization_id,
				'OrganizationsUser.user_id = User.id',
				'NOT' => array(
					'User.id' => Hash::extract($existing_users_in_events, '{n}.id')
				)
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
			'limit'=>6,
			'fields'=>array(
				'id', 'firstname', 'lastname', 'email'
			),
			'recursive' => -1
		));
		$users = Hash::extract($users_raw, '{n}.User');
		
		//Then we diff out those in invitations emails
		
		$final_users = array();
		foreach($users as $u) {
			if (!in_array($u['email'], $existing_users_in_invitation_emails)) {
				$final_users[] = $u;
			}
		}
		
		//Calculate avatar
		foreach($final_users as &$u) {
			$u['avatar'] = Gravatar::Avatar($u['email'], 24);
		}
		unset($u);
		
		$this->responseSuccess($final_users);
	}
}
