<?php
App::uses('AppController', 'Controller');
class OrganizersController extends AppController {
	
	public $uses = array('Organization', 'Organizer');
	
	public function beforeFilter() {
		parent::beforeFilter();
		$this->Auth->allow('contact');
	}
	/**
	 * Get all organizers from a organization
	 * @param type $organization_id
	 */
	public function all($organization_id) {
		$organizers = $this->Organizer->find('all', array(
			'conditions' => array(
				'Organizer.organization_id' => $organization_id
			),
			'recursive' => -1
		));
		
		$this->responseSuccess(Hash::extract($organizers, '{n}.Organizer'));
	}
	
	
	public function read($organization_id, $organizer_id) {
		$organizer = $this->Organizer->find('first', array(
			'conditions' => array(
				'Organizer.organization_id' => $organization_id,
				'Organizer.id' => $organizer_id
			),
			'recursive' => -1
		));
		
		if (!empty($organizer)) {
			$this->responseSuccess($organizer['Organizer']);
		}else{
			$this->responseFail();
		}
	}
	
	/**
	 * Create organizer under this organization
	 */
	public function update($organization_id, $organizer_id) {
		//find organizer first, for ownership test
		$organizer = $this->Organizer->find('first', array(
			'conditions' => array(
				'Organizer.organization_id' => $organization_id,
				'Organizer.id' => $organizer_id
			),
			'recursive' => -1
		));
		
		if (!empty($organizer)) {
			
			$this->Organizer->set($this->data);
			$this->Organizer->set('id', $organizer_id);
			$this->Organizer->set('organization_id', $organization_id);
			
			if ($organizer = $this->Organizer->save()) {
				$this->responseSuccess($organizer['Organizer']);
			}else{
				$this->flashError($this->Organizer->validationErrorsAsText());
			}
			
			$this->responseSuccess($organizer['Organizer']);
		}else{
			$this->responseFail();
		}
	}
	
	/**
	 * Create organizer under this organization
	 */
	public function create($organization_id, $event_id=0) {
		
		if ($this->request->is('post')) {
			
			$this->Organizer->create();
			$this->Organizer->set($this->data);
			$this->Organizer->set('id', null);
			$this->Organizer->set('organization_id', $organization_id);
			
			if ($organizer = $this->Organizer->save()) {
				if (isset($this->data['ajax'])) {
					$this->responseSuccess($organizer['Organizer']);
				}else{
					$this->flashSuccess('Successfully created organizer');
					$this->redirect(array(
						'controller' => 'Organizations',
						'action' => 'home',
						'organization_id' => $organization_id
					));
				}
			}else{
			   if($this->request->is('ajax')) {
					$this->responseFail($this->Organizer->validationErrorsAsText());
			   } else {
					$this->flashError($this->Organizer->validationErrorsAsText()); 
			   }
			}
		}
	}
	
	public function remove($organization_id, $organizer_id) {
		
	}
	
	/**
	 * Contact organizer
	 */
	public function contact() {
		
		$EventModel = ClassRegistry::init('Event');
		
		$emailaddress = trim($this->data['email']);
		$name = trim($this->data['name']);
		$content = trim($this->data['content']);
		$event_id = $this->data['event_id'];
		
		$event = $EventModel->find('first', array(
			'conditions' => array(
				"{$EventModel->alias}.id" => $event_id
			),
			'recursive' => -1,
			'contain' => array(
				'Organizer'
			)
		));
		
		//Validate
		$valid = true;
		$errors = array();
		
		if (empty($name)) {
			$valid = false;
			$errors[] = 'Name should\'t be empty';
		}
		if (!filter_var($emailaddress, FILTER_VALIDATE_EMAIL)) {
			$valid = false;
			$errors[] = 'Email address is invalid';
		}
		if (empty($content)) {
			$valid = false;
			$errors[] = 'Content shouldn\'t be empty';
		}
		if (empty($event)) {
			$valid = false;
			$errors[] = 'Internal error: Event not found';
		}
		
		if ($valid) {
            if(!empty($event['Event']['contact_email'])){
                $contact_email=$event['Event']['contact_email'];
            }else{
                $contact_email=$event['Organizer']['email'];
            }
			$email = new CakeEmail();
			$email->config('smtp')
				  ->template('contact_organizer', 'plain')
				  ->emailFormat('html')
				  ->viewVars(array(
					  'name' => $name,
					  'email_address' => $emailaddress,
					  'email_content' => $content,
					  'event' => $event
				  ))
				  ->to($contact_email)
				  ->from($emailaddress)
				  ->subject('[GEVME] Event Enquiry')
				  ->send();
				  
			$this->responseSuccess();
		}else{
			$this->responseFail(implode(", ", $errors));
		}
	}
}