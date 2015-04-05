<?php
App::uses('AppController', 'Controller');
class FeedAttachmentsController extends AppController {

	public $name = 'FeedAttachments';
	
	public function view($organization_id, $event_id, $attachment_id) {
	
		//Get the attachment and redirect it
		$this->FeedAttachment->recursive = -1;
		$attachment = $this->FeedAttachment->findById($attachment_id);
		
		//Ownership check
		if (empty($attachment) || !$this->FeedAttachment->Feed->hasAccess($this->Auth->user('id'), $attachment['FeedAttachment']['feed_id'])){
			$this->link_error();
			return;
		}
		
		
		if (!empty($attachment)) {
			$url = !empty($attachment['FeedAttachment']['file']) ? $attachment['FeedAttachment']['file'] : $attachment['FeedAttachment']['url'];
		}
		
		//bye~
		$this->autoRender = false;
		header("Content-Type: {$attachment['FeedAttachment']['mimetype']}");
		$this->response->type($attachment['FeedAttachment']['mimetype']);
		readfile($url);
	}
	
	public function getAll($t) {
		
	}
}