<?php
class OnsiteController extends AppController {

	public $components = array(
		'GAcl.GAcl' => array(
			'aco' => 'onsite_settings',
			'actions' => array(
				'home' => ACL_ACTION_READ,
				'eventInfo' => ACL_ACTION_READ,
				'updateOnsite' => ACL_ACTION_UPDATE
			)
		)
	);
	
	public $uses = array('Event', 'EventSetting', 'OnsiteSession', 'Printer.PrinterTemplate');
	
	public $layout = 'default';
	
	/**
	 * Onsite page
	 * 
	 * @param unknown $organization_id
	 * @param unknown $event_id
	 */
	public function home($organization_id, $event_id) {
		$this->layout = 'default';
		$this->setTitle('On Site');
	}
	
	/**
	 * Get onsite settings of event
	 * 
	 * @param unknown $organization_id
	 * @param unknown $event_id
	 */
	public function eventInfo($organization_id, $event_id) {
		$sessions = $this->OnsiteSession->find('all', array('recursive' => -1, 'conditions' => array('event_id' => $event_id)));
		array_walk($sessions, 'removeClassName', $this->OnsiteSession->alias);
		
		$result = $this->EventSetting->find('first', array('recursive' => -1, 'conditions' => array('event_id' => $event_id)));
		$settings = $result[$this->EventSetting->alias];

		$RegTickets = ClassRegistry::init('Registration.RegistrationTicket');
		$tickets    = $RegTickets->getTickets($event_id);

		$PrinterTemplate = ClassRegistry::init('Printer.PrinterTemplate');
		$templates       = $PrinterTemplate->getTemplates($event_id);
		
		return $this->responseSend(compact('settings', 'sessions', 'tickets', 'templates'));
	}
	
	/**
	 * Update onsite information
	 * 
	 * @param unknown $organization_id
	 * @param unknown $event_id
	 */
	public function updateOnsite($organization_id, $event_id) {
		$result = $this->EventSetting->find('first', array('recursive' => -1, 'conditions' => array('event_id' => $event_id)));
		
		$sessions = $this->data['sessions'];

		array_walk($sessions, function(&$item) {
			if('' === $item['session_limit']) {
				$item['session_limit'] = null;
			} else if('true' === $item['session_limit'] || 'false' === $item['session_limit']) {
				$item['session_limit'] = 'true' === $item['session_limit'];
			}
		});

		$settings = $this->data['settings'];
		
		unset($settings['event_id']);
		unset($settings['id']);
		
		//Attempt to create one if not found, or just merge it
		if (!empty($result[$this->EventSetting->alias]['id'])) {
			$settings['id'] = $result[$this->EventSetting->alias]['id'];
		}
		
		App::uses('HTMLPurifierHelper', 'Lib');
		foreach($settings as &$s) {
			$s = HTMLPurifierHelper::purify($s);
		}
		
		$this->EventSetting->save($settings);
		$this->OnsiteSession->saveMany($sessions);

		$event = $this->Event->find('first', array('recursive' => -1, 'conditions' => array('Event.id' => $event_id)));

		if (isset($this->data['tickets']) && !empty($this->data['tickets'])) {
			$RegistrationTicket = ClassRegistry::init('Registration.RegistrationTicket');
			$RegistrationTicket->saveTickets($event['Event'], $this->data['tickets'], $this->Auth->user());
		}

		if (isset($this->data['templates']) && !empty($this->data['templates'])) {
			$PrinterTemplate = ClassRegistry::init('Printer.PrinterTemplate');
			$PrinterTemplate->saveTemplates($event_id, $this->data['templates'], $this->Auth->user());
		}

		return $this->responseSuccess();
	}
}