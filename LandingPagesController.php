<?php
App::uses('AppController', 'Controller');

class LandingPagesController extends AppController {
	
	public $components = array(
		'GAcl.GAcl' => array(
			'aco' => 'event_page'
		)
	);

    public $uses = array(
        'LandingPage', 'Registration.RegistrationSetting'
    );
		
	public function settings($organization_id, $event_id) {
		$this->layout = 'default';
		
		$event = $this->LandingPage->Event->find('first', array(
			'conditions' => array(
				'Event.organization_id' => $organization_id,
				'Event.id' => $event_id
			),
			'contain' => array(
				'LandingPage'
			),
			'recursive' => -1
		));

        //Get the return_url too
        $return_url = $this->RegistrationSetting->getOne($event_id, 'registration_return_url');
        $event['Event']['registration_return_url'] = $return_url;
        $this->LandingPage->Event->set('registration_return_url', $return_url);

		$this->set('event', $event);
				
		if ($this->request->is('put')) {
			$this->GAcl->check(null, ACL_ACTION_UPDATE);
			//debug($this->data);exit;
			//Update events and landing page
			$this->LandingPage->Event->set($this->data['Event']);
			$this->LandingPage->Event->set('id', $event_id);
			$this->LandingPage->Event->set('organization_id', $organization_id);
			
			//Find the landing page
			$landingPage = $this->LandingPage->find('first', array(
				'conditions' => array(
					'LandingPage.event_id' => $event_id
				)
			));
			
			
			$this->LandingPage->set($this->data['LandingPage']);
			$this->LandingPage->set('event_id', $event_id);
			
			//We give a room to landing page to create if there is none.
			//This is to update the existing one
			if (!empty($landingPage)) {
				$this->LandingPage->set('id', $landingPage['LandingPage']['id']);
			}
						
			if ($this->LandingPage->validates() && $this->LandingPage->Event->validates()) {
				
				$this->LandingPage->Event->save(null, false);
				$this->LandingPage->save(null, false);

                //Also save the registration_return_url
                $return_url = $this->request->data('Event.registration_return_url');
                $this->RegistrationSetting->setData($event_id, 'registration_return_url', $return_url);

				//search again
				$event = $this->LandingPage->Event->find('first', array(
					'conditions' => array(
						'Event.organization_id' => $organization_id,
						'Event.id' => $event_id
					),
					'contain' => array(
						'LandingPage'
					),
					'recursive' => -1
				));
                $event['Event']['registration_return_url'] = $return_url;
                $this->LandingPage->Event->set('registration_return_url', $return_url);
				$this->set('event', $event);
								
				$this->flashSuccess(__('Successfully saved landing page setting'));
				
			}else{
				$this->flashError($this->LandingPage->Event->validationErrorsAsText().$this->LandingPage->validationErrorsAsText());
			}
		} else {
			$this->GAcl->check(null, ACL_ACTION_READ);
		}
		
		$this->data = array(
			'Event' => $event['Event'],
			'LandingPage' => $event['LandingPage']
		);
		
		$this->setTitle('Event Page');
	}
	
	public function preview($organization_id, $event_id) {
		
		$this->layout = 'landing_page';
		
		$event = $this->LandingPage->Event->find('first', array(
			'conditions' => array(
				'Event.id' => $event_id
			),
			'contain' => array(
				'LandingPage', 'Organizer'
			)
		));
				
		//If we have posted data, we save it in the preview
		if (!empty($this->data)) {
			
			//For security purpose, purify html data
			$data = $this->data;
			App::uses('HTMLPurifierHelper', 'Lib');
			$data['Event']['description'] = HTMLPurifierHelper::purify($data['Event']['description']);
			$this->Session->write('landing_page_preview_data', Hash::merge($event, $data));
			
			
			
		}else{

			if (empty($event['LandingPage'])) {
				$this->redirect(array(
					'controller' => 'LandingPages',
					'action' => 'settings',
					'organization_id' => $this->params['organization_id'],
					'event_id' => $this->params['event_id']
				));
				return;
			}
			
			if ($this->request->query('preview') && $this->Session->check('landing_page_preview_data')) {
				$this->set('event', $this->Session->read('landing_page_preview_data'));
			}else{
				$this->set('event', $event);
			}

			$this->setTitle($event['Event']['name']);
		}
	}
}