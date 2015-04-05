<?php
App::uses('AppController', 'Controller');
App::uses('OpenGraph', 'Lib');
App::uses('FacebookGraph', 'Lib');
App::uses('Gravatar', 'Lib');

App::uses('String', 'Utility');
App::uses('EmailView', 'View');

App::uses('Cipher', 'Lib');

//App::uses('OpenGraphTwo', 'Lib');
class FeedsController extends AppController {

	public $name = 'Feeds';
	public $uses = array('Feed');
	public $components = array('EmailNotifier');
	public $paginate;
	
	public function beforeFilter() {
		parent::beforeFilter();
		$this->Auth->allow('unsubscribe', 'subscribe');
	}
	
	public function og() {
		$q = $this->request->query['q'];
		
//		$graph = OpenGraph::fetch($q);
//		
//		var_dump($graph);
//		exit;
//		var_dump($graph->keys());
//		var_dump($graph->schema);
//
//		$this->autoRender = false;
//		return;
		
		$og = OpenGraph::GetGraph($q);
		
		if (!empty($og)) {
			$this->responseSuccess($og);
		}else{
			$this->responseFail();
		}
	}
	
	public function view($organization_id, $event_id, $feed_id) {

	}
	
	public function fetch_one($organization_id, $event_id, $feed_id) {
		$user_id = $this->Auth->user('id');
		
		$this->Feed->virtualFields['removable'] = "Feed.poster_id={$user_id} AND Feed.poster_type='user'";
		$this->Feed->FeedComment->virtualFields['removable'] = "FeedComment.user_id={$user_id}";
		$this->Feed->FeedComment->virtualFields['liked'] = "{$user_id} IN (SELECT user_id FROM feed_comment_likes WHERE feed_comment_id=FeedComment.id)";
		
		$feed = $this->Feed->find('first', array(
			'conditions' => array(
				'Feed.id'=> $feed_id
			),
			'recursive' => 2,
			'fields' => array(
				'Feed.*', 'UNIX_TIMESTAMP(Feed.created) as created_timestamp'
			),
			'contain' => array(
				'FeedAttachment',
				'FeedLink',
				'FeedComment' => array(
					'FeedAttachment',
					'FeedCommentLike',
					'order' => 'created DESC',
					'fields' => array(
						'FeedComment.*', 'UNIX_TIMESTAMP(FeedComment.created) as created_timestamp'
					)
				),
				'FeedLike'
			)
		));
		
		//Find userFeed and attempt to merge
		$user_feed = $this->Feed->UserFeed->find('first', array(
			'conditions' => array(
				'UserFeed.user_id' => $user_id,
				'UserFeed.feed_id' => $feed_id
			)
		));
		
		if (!empty($user_feed)) {
			$feed = array_merge($feed, $user_feed);
		}
		
	
		
		if (!empty($feed)) {
			$feed['Feed']['created_timestamp'] = $feed[0]['created_timestamp'];
			unset($feed[0]);
			foreach($feed['FeedComment'] as &$comment) {
				$comment['created_timestamp'] = $comment['FeedComment'][0]['created_timestamp'];
				unset($comment['FeedComment']);
			}
			unset($comment);
		}
		
		$this->responseSuccess($feed);
	}
	
	/**
	 * Fetch feeds by org_id & event_id, or optionally just pass in organization_id
	 * 
	 * @param type $organization_id
	 * @param type $event_id
	 */
	public function fetch($organization_id, $event_id=0) {
								
		$page = isset($this->params->query['page']) ? $this->params->query['page'] : 1;
		$limit = isset($this->params->query['limit']) ? $this->params->query['limit'] : 10;
		$last_id = isset($this->params->query['last_id']) ? $this->params->query['last_id'] : 0;
		$important = isset($this->params->query['important']) ? $this->params->query['important'] : null;
		$all = isset($this->params->query['all']) ? $this->params->query['all'] : 0;
		$shorten = isset($this->params->query['shorten']) ? $this->params->query['shorten'] : 0;
		
		//$user_id, $organization_id, $event_id=0, $page=1, $limit=15
		$feeds = $this->Feed->getAll($this->Auth->user('id'), $organization_id, $event_id, $page, $all ? 0 : $limit, $last_id, $important!==null ? array(
			'UserFeed.marked_important' => $important
		) : array());
		
		if ($shorten) {
			foreach($feeds as &$feed) {
				$stripped = strip_tags($feed['Feed']['body']);
				$feed['Feed']['body'] = String::truncate($stripped, 80);
			}
			unset($feed);
		}
		
//		foreach($feeds as &$feed) {
//			$feed['Feed']['body'] = String::truncate($feed['Feed']['body'], 400, array('html'=>true));
//			
//			foreach($feed['FeedComment'] as &$comment) {
//				$comment['body'] = String::truncate($comment['body'], 400, array('html'=>true));
//			}
//			unset($comment);
//		}
//		unset($feed);
		$this->responseSuccess($feeds);
	}
	
	public function create($organization_id, $event_id) {		
		
		//debug($this->params, 0);
		
		$avatar = $this->Auth->user('avatar');
		
		$this->Feed->create();
		$this->Feed->blacklist = array('id', 'comments', 'likes');
		$this->Feed->set($this->request->data);
		$this->Feed->set(array(
			'poster_id' => $this->Auth->user('id'),
			'poster_image' => !empty($avatar) ? $avatar : Gravatar::Avatar($this->Auth->user('email')),
			'poster_name' => $this->Auth->user('name'),
			'poster_type' => 'user',
			'organization_id' => $organization_id,
			'event_id' => $event_id,
			'title' => $this->request->data['Feed']['title'],
			'body' => $this->request->data['Feed']['body']
		));
		
		if ($feed = $this->Feed->save()) {
			
			//debug($feed);
			$this->notifySubscribers($feed);
			
			//response
			$this->responseSuccess($feed);
		}else{
			$this->responseFail('Unknown error');
		}
	}
	
	/**
	 * Similar to create, but is via api creation
	 * 
	 * @param type $organization_id
	 * @param type $event_id
	 */
	public function module_create($organization_id, $event_id) {
		
		$this->Feed->create();
		$this->Feed->blacklist = array('id', 'comments', 'likes');
		$this->Feed->set($this->request->data);
		$this->Feed->set(array(
			'poster_id' => $this->params['module_id'], //module_id is set via ModuleAuthenticate
			'poster_image' => $this->params['module_image'], //module_image is set via ModuleAuthenticate
			'poster_name' => $this->params['module_name'], //module_name is set via ModuleAuthenticate
			'poster_type' => 'module',
			'organization_id' => $organization_id,
			'event_id' => $event_id,
			'title' => $this->request->data['title'],
			'body' => $this->request->data['body'],
			'action' => $this->request->data['action']
		));
				
		if ($feed = $this->Feed->save()) {
			//response
			$this->responseSuccess($feed);
		}else{
			$this->responseFail('Unknown error');
		}
	}
	
	public function update($organization_id, $event_id, $feed_id) {
		
		$user_id = $this->Auth->user('id');
		$user_name = $this->Auth->user('name');
		
		$hasAccess = $this->Feed->hasAccess($user_id, $feed_id);
		
		if (!$hasAccess) {
			$this->responseFail('Illegal access');
			return;
		}
		
		//PUT data
		$data = $this->request->input('json_decode', true);
		
		
		//check if this user is the owner of feed or not first
		//as owner can modify the content of the feed.
		//Non owner can only like, mark important on the feed
		$isOwner = $this->Feed->isOwner($user_id, $feed_id);
		
		//Find the userfeed
		$userFeed = $this->Feed->UserFeed->find('first', array(
			'recursive' => -1,
			'conditions'=> array(
				'UserFeed.user_id' => $user_id,
				'UserFeed.feed_id' => $feed_id
			)
		));
		
		$this->Feed->recursive = -1;
		$feed = $this->Feed->findById($feed_id);
		
		//If user_id or feed_id mismatched, kill
		if (empty($userFeed) || empty($feed)) {
			$this->responseFail('Illegal access');
			return;
		}
		
		//then we can start changing stuff
		
		//1. Update like status 
		if (isset($data['UserFeed']['liked']) && $userFeed['UserFeed']['liked'] != $data['UserFeed']['liked']) {
			$this->Feed->UserFeed->set('liked', $data['UserFeed']['liked']);
			//$this->Feed->set('likes', $feed['Feed']['likes'] + ($data['UserFeed']['liked'] ? 1 : -1));
			
			//Also minger with feedlikes
			if ($data['UserFeed']['liked']) {
				//create like
				$this->Feed->FeedLike->create();
				$this->Feed->FeedLike->save(array(
					'feed_id' => $feed_id,
					'user_id' => $user_id,
					'user_name' => $user_name
				));
			}else{

				$this->Feed->FeedLike->deleteAll(array(
					'FeedLike.feed_id' => $feed_id,
					'FeedLike.user_id' => $user_id
				));
				$this->Feed->FeedLike->updateCounterCache(array('feed_id'=>$feed_id));
			}
		}
		
		//2. update important status
		if (isset($data['UserFeed']['marked_important']) && $userFeed['UserFeed']['marked_important'] != $data['UserFeed']['marked_important']) {
			$this->Feed->UserFeed->set('marked_important', $data['UserFeed']['marked_important']);
		}
		
		//$this->Feed->set('id', $feed['Feed']['id']);
		$this->Feed->UserFeed->set('id', $userFeed['UserFeed']['id']);
		
		//$this->Feed->save();
		$this->Feed->UserFeed->save();
		
		if ($isOwner) {
			
			if (isset($data['body']) && isset($data['title'])) {
				$this->Feed->save(array(
					'id'=>$feed['Feed']['id'],
					'body'=>$data['body'],
					'title' => $data['title']
				));
			}
			
		}else{
			
		}
		
		//Update like status
		$this->responseEmpty();
	}
	
	public function delete($organization_id, $event_id, $feed_id) {
		
		$isOwner = $this->Feed->isOwner($this->Auth->user('id'), $feed_id);
		
		if ($isOwner) {
			$this->Feed->delete($feed_id);
			$this->responseEmpty();
		}else{
			$this->responseFail('Illegal access');
		}
	}
	
	public function latest_feed($organization_id, $event_id) {
		
		//Find the latest feed and dump yes or not to the window
		$last_id = isset($this->params->query['last_id']) ? $this->params->query['last_id'] : 0;
		
		
		$hasNewPost = $this->Feed->UserFeed->hasAny(array(
			'UserFeed.event_id' => $event_id,
			'UserFeed.user_id' => $this->Auth->user('id'),
			'UserFeed.feed_id >' => $last_id
		));
		
		$this->responseSend((int) $hasNewPost);
		
	}
	
	private function notifySubscribers($feed) {
		
		$feed_id = $feed['Feed']['id'];
		
		//Get subscribers out
		$subscribers = $this->Feed->getSubscribers($feed_id, $this->Auth->user('id')); //exclude myself
		
		//nothing to do, if subscribers is empty
		if (empty($subscribers)) return true;
		
		$feed = $this->Feed->find('first', array(
			'conditions' => array(
				'Feed.id' => $feed_id
			),
			'contain'=>array(
				'FeedAttachment', 'FeedComment', 'User' => array(
					'fields' => array('id', 'name')
				), 'Event' => array(
					'fields' => array('id', 'name')
				)
			)
		));
				
		
		//-----------------------------------
		//- Render
		//-----------------------------------
				
		$feed_title = !empty($feed['Feed']['title']) ? $feed['Feed']['title'] : String::truncate(strip_tags($feed['Feed']['body']), 30);
		$from = array($this->Auth->user('email') => $this->Auth->user('name').' (GEVME)');
		$subject = "[{$feed['Event']['name']}] ".$feed_title;
		$messages = array();
		
		foreach($subscribers as $subscriber) {
			$unsubscribe_cipher = Cipher::Encrypt(json_encode(array('user_id'=>$subscriber['User']['id'], 'feed_id'=>$feed_id)));
			$unsubscribe_link = Router::url("/unsubscribe?token=".urlencode($unsubscribe_cipher), true);
			
//			$view = new EmailView();
//			$view->set('feed', $feed);
//			$view->set('subscribers', $subscribers);
//			$view->set('unsubscribe_link', $unsubscribe_link);
//			$rendered = $view->render('feed', 'default');
//			unset($view);
			
			$messages[] = array(
				'subject' => $subject,
				'from' => $from,
				'viewVars' => array(
					'feed' => $feed,
					'subscribers' => $subscribers,
					'unsubscribe_link' => $unsubscribe_link
				),
				'email' => 'feed',
				'layout' => 'default',
				'to' => array($subscriber['User']['email'] => $subscriber['User']['name'])
			);
		}
		
		$this->EmailNotifier->notify($messages);
		return true;
	}
	
	public function unsubscribe() {
		
		$this->layout = 'plain';
		
		if (!isset($_GET['token'])) {
			$this->redirect('/');
		}
		
		$token = $_GET['token'];
		$unencrypted_data = json_decode(Cipher::Decrypt($token), true);
				
		if (!empty($unencrypted_data)) {
			$user_id = $unencrypted_data['user_id'];
			$feed_id = $unencrypted_data['feed_id'];
			
			if ($user_id > 0 && $feed_id > 0) {
				$this->Feed->unsubscribe($user_id, $feed_id);
				$this->set('title', __('You have been successfully unsubscribed'));
				$this->set('body', __('You will not receive any email notifications again when new comments are posted. However, you will receive it again if you comment to the same post again.'));
				$this->set('subscribe_link', Router::url('/subscribe?token='.urlencode($token)));
			}else{
				$this->set('title', __('Token is not correct!'));
				$this->set('body', __('You may have typed the URL incorrectly. Please check to make sure you\'ve got the URL right'));
			}
		}else{
			$this->set('title', __('Token is not correct!'));
			$this->set('body', __('You may have typed the URL incorrectly. Please check to make sure you\'ve got the URL right'));
		}
		
		$this->render('subscription');
	}
	
	public function subscribe() {
		
		$this->layout = 'plain';
		
		if (!isset($_GET['token'])) {
			$this->redirect('/');
		}
		
		$token = $_GET['token'];
		$unencrypted_data = json_decode(Cipher::Decrypt($token), true);
		
		if (!empty($unencrypted_data)) {
			$user_id = $unencrypted_data['user_id'];
			$feed_id = $unencrypted_data['feed_id'];
			
			if ($user_id > 0 && $feed_id > 0) {
				$this->Feed->subscribe($user_id, $feed_id);
				$this->set('title', __('You have been successfully re-unsubscribed'));
				$this->set('body', __('You will receive email notifications again when new comments are posted.'));
			}else{
				$this->set('title', __('Token is not correct!'));
				$this->set('body', __('You may have typed the URL incorrectly. Please check to make sure you\'ve got the URL right'));
			}
		}else{
			$this->set('title', __('Token is not correct!'));
			$this->set('body', __('You may have typed the URL incorrectly. Please check to make sure you\'ve got the URL right'));
		}
		
		$this->render('subscription');
	}
	
	
	/**
	 * Get subscription info
	 * 
	 * @param type $organization_id
	 * @param type $event_id
	 */
	public function subscription_info($organization_id, $event_id, $feed_id) {
		$my_id = $this->Auth->user('id');
		$events_users = $this->Feed->Event->EventsUser->find('all', array(
			'conditions'=>array(
				'EventsUser.event_id' => $event_id,
				'EventsUser.user_id <>' => $my_id
			),
			//'fields'=>array('id', 'invited_by', 'modules', 'organization_id'),
			'contain' => array(
				'User' => array(
					'fields' => array('firstname', 'lastname', 'id', 'name')
				)
			)
		));
		
		//Get subscribers to this feed
		$subscribers = $this->Feed->FeedSubscription->find('all', array(
			'conditions' => array(
				'FeedSubscription.feed_id' => $feed_id,
				'FeedSubscription.user_id <>' => $my_id
			),
			'contain' => array(
				'User' => array(
					'fields' => array('id', 'name')
				)
			)
		));
		
		$all_users = Hash::extract($events_users, '{n}.User');
		$all_subscribers = Hash::extract($subscribers, '{n}.User');
		
		$this->responseSuccess(array('users'=>$all_users, 'subscribers' => $all_subscribers));
	}
}