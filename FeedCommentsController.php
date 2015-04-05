<?php
App::uses('AppController', 'Controller');
App::uses('Gravatar', 'Lib');
App::uses('String', 'Utility');
App::uses('EmailView', 'View');
App::uses('Cipher', 'Lib');

class FeedCommentsController extends AppController {

	public $name = 'FeedComments';
	public $uses = array('FeedComment');
	public $components = array('Socket', 'EmailNotifier');
	
	public function create($organization_id, $event_id, $feed_id) {		
		//Post comments!
			
		$this->FeedComment->create();
		$this->FeedComment->blacklist = array('id', 'likes');
		$this->FeedComment->set($this->request->data);
		$this->FeedComment->set(array(
			'user_id' => $this->Auth->user('id'),
			'user_image' => Gravatar::Avatar($this->Auth->user('email')),
			'user_name' => $this->Auth->user('name'),
			'poster_type' => 'user',
			'poster_id' => $this->Auth->user('id'),
			'organization_id' => $organization_id,
			'event_id' => $event_id,
			'body' => $this->request->data['FeedComment']['body'],
			'feed_id' => $feed_id
		));
		
		if ($feedComment = $this->FeedComment->save()) {
			
			//Find again
			$feedComment = $this->FeedComment->find('first', array(
				'conditions' => array(
					'FeedComment.id' => $feedComment['FeedComment']['id']
				),
				'recursive' => -1,
				'fields' => array(
					'FeedComment.*', 'UNIX_TIMESTAMP(FeedComment.created) as created_timestamp'
				)
			));
			
			$feedComment['FeedComment']['created_timestamp'] = $feedComment[0]['created_timestamp'];
			
			$this->notifySubscribers($feedComment);

//			//broadcast it
//			$this->Socket->send(array(
//				'user_id' => $sender['Account']['id'],
//				'module' => 'feed',
//				'action' => 'newcomment',
//				'feed_id' => $feedComment['FeedComment']['feed_id'],
//				'feed_comment_id' => $feedComment['FeedComment']['id']
//			));
			
			//response
			$this->responseSuccess($feedComment);
		}else{
			$this->responseFail('Unknown error');
		}
		
	}
	
	/**
	 * Update comment meta
	 * 
	 * @param int $organization_id
	 * @param int $event_id
	 * @param int $feed_id
	 * @param int $comment_id
	 */
	public function update($organization_id, $event_id, $feed_id, $comment_id) {
		
		$hasAccess = $this->FeedComment->Feed->hasAccess($this->Auth->user('id'), $feed_id);
		$isOwner = $this->FeedComment->isOwner($this->Auth->user('id'), $comment_id);
		
		if (!$hasAccess) {
			$this->responseFail('Illegal access');
			return false;
		}
		
		//Update likes info
		$user_id = $this->Auth->user('id');
		$user_name = $this->Auth->user('name');
		
		$this->FeedComment->virtualFields['liked'] = "{$user_id} IN (SELECT user_id FROM feed_comment_likes WHERE feed_comment_id=FeedComment.id)";
		$this->FeedComment->recursive = -1;
		$feedComment = $this->FeedComment->findById($comment_id);
		
		if (empty($feedComment)) {
			$this->responseFail('Illegal access');
			return false;
		}
		
		$data = $this->request->input('json_decode', true);
		
		//If like changed
		if (isset($data['liked']) && $feedComment['FeedComment']['liked'] != $data['liked']) {
			$this->FeedComment->setLike($user_id, $user_name, $feedComment['FeedComment']['id'], $data['liked']);
		}
		
		//Owner can edit the info, if needed
		if ($isOwner && isset($data['body'])) {
			$this->FeedComment->save(array(
				'id' => $comment_id,
				'body' => $data['body']
			));
		}
		
		
		$this->responseEmpty();
	}
	
	public function fetch($organization_id, $event_id, $feed_id) {
		//Check if the user has access to feed first
		$hasAccess = $this->FeedComment->Feed->hasAccess($this->Auth->user('id'), $feed_id);
		if (!$hasAccess) {
			$this->responseFail('Illegal access');
			return;
		}
		
		//All or last 4 comments
		$all = isset($this->params->query['all']);
		
		$conditions = array(
			'FeedComment.feed_id' => $feed_id
		);
		
		if (isset($this->params->query['newest_id'])) {
			$conditions['FeedComment.id >'] = $this->params->query['newest_id'];
		}
		
		if (isset($this->params->query['oldest_id'])) {
			$conditions['FeedComment.id <'] = $this->params->query['oldest_id'];
		}
		
		//Fetch comments!
		$this->FeedComment->virtualFields['removable'] = "FeedComment.user_id={$this->Auth->user('id')}";
		$comments = $this->FeedComment->find('all', array(
			'conditions' => $conditions,
			'contain' => array(
				'FeedAttachment', 'FeedCommentLike'
			),
			'order' => 'created DESC',
			'fields' => array(
				'FeedComment.*', 'UNIX_TIMESTAMP(FeedComment.created) as created_timestamp'
			)
		));
		
		//Rearrange the array. FeedComment need to be the first level
		$returnData = array();
		
		foreach($comments as $comment) {
			$commentData = $comment['FeedComment'];
			unset($comment['FeedComment']);
			
			$returnData[] = array_merge($commentData, $comment, $comment[0]);
		}
		
		array_walk($returnData, function(&$rd) {
			$rd['user_image'] = Router::url('/users/avatar/'.Security::hash($rd['user_id'], 'sha1', true).'.jpg', true);
		});
		
		$this->responseSuccess($returnData);
	}
	
	public function delete($organization_id, $event_id, $feed_id, $comment_id) {
		
		//Only owner can remove comment
		$isOwner = $this->FeedComment->isOwner($this->Auth->user('id'), $comment_id);
		
		if ($isOwner) {
			$this->FeedComment->delete($comment_id);
			$this->responseEmpty();
		}else{
			$this->responseFail('Illegal access');
		}
	}
	
	private function notifySubscribers($comment) {
		
		$feed = $this->FeedComment->Feed->find('first', array(
			'conditions' => array(
				'Feed.id' => $comment['FeedComment']['feed_id']
			),
			'contain' => array(
				'Event' => array(
					'fields' => array('id', 'name')
				)
			)
		));
		
		//If we ever see subscribers, we going to respect the subscriber list, not the
		//subscribers from database
		
		if (isset($comment['FeedComment']['subscriber_mode']) && $comment['FeedComment']['subscriber_mode'] == 'list') {
			
			//Check the subscriber list. If empty, we do not need to do anything
			if (isset($comment['FeedComment']['subscribers']) && !empty($comment['FeedComment']['subscribers'])) {
			
				$subscriber_ids = $comment['FeedComment']['subscribers'];
				
				$subscribers = $this->FeedComment->User->find('all', array(
					'fields' => array(
						'User.id', 'User.name', 'User.firstname', 'User.lastname', 'User.email'
					),
					'conditions' => array(
						'User.id' => $subscriber_ids
					),
					'recursive' => -1
				));				
			}else{
				$subscribers = false;
			}
			
		}else{
			$subscribers = $this->FeedComment->Feed->getSubscribers($comment['FeedComment']['feed_id'], $this->Auth->user('id'));
		}
		
		//nothing to do, if subscribers is empty
		if (empty($subscribers)) return true;
				
		
		//-----------------------------------
		//- Render
		//-----------------------------------
		$feed_title = !empty($feed['Feed']['title']) ? $feed['Feed']['title'] : String::truncate(strip_tags($feed['Feed']['body']), 30);
		$from = array($this->Auth->user('email') => $this->Auth->user('name').' (GEVME)');
		$subject = "[{$feed['Event']['name']}] ".$feed_title;
		$messages = array();
		
		foreach($subscribers as $subscriber) {
			$unsubscribe_cipher = Cipher::Encrypt(json_encode(array('user_id'=>$subscriber['User']['id'], 'feed_id'=>$feed['Feed']['id'])));
			$unsubscribe_link = Router::url("/unsubscribe?token=".urlencode($unsubscribe_cipher), true);
			
//			$view = new EmailView();
//			$view->set('feed', $feed);
//			$view->set('comment', $comment);
//			$view->set('subscribers', $subscribers);
//			$view->set('unsubscribe_link', $unsubscribe_link);
//			$rendered = $view->render('comment', 'default');
//			unset($view);
			
			$messages[] = array(
				'subject' => $subject,
				'from' => $from,
				'viewVars' => array(
					'feed' => $feed,
					'comment' => $comment,
					'subscribers' => $subscribers,
					'unsubscribe_link' => $unsubscribe_link
				),
				'email' => 'comment',
				'layout' => 'default',
				'to' => array($subscriber['User']['email'] => $subscriber['User']['name'])
			);
		}
		
		$this->EmailNotifier->notify($messages);
		return true;
	}
}