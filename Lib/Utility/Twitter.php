<?php
/**
 * Twitter.php - The main utility file
 *
 * This is a plugin for CakePHP to connect your app with the Twitter API using OAuth.
 * With this plugin it's possible to access the main API methods
 * (such as status updates, timelines or user) of the Twitter API in all of your controllers.
 * You even have the opportunity to make custom API-Calls with this plugin.
 *
 * @author Florian Nitschmann (f.nitschmann@media-n.net)
 * @link www.media-n.net
 * @copyright (c) 2011 media-n (www.media-n.net)
 * @license MIT License - http://www.opensource.org/licenses/mit-license.php
 *
 * CakePHP 2.x
 * @conttrib mishu.drk@gmail.com
 */
App::import('Vendor', 'Twitter.HttpSocketOauth');
App::uses('CakeSession', 'Model/Datasource');

class Twitter extends Object {

/**
 * Twitter consumer key & consumer secret
 *
 * @access protected
 * @var string $consumerKey The OAuth consumer key
 * @var string $consumerSecret The OAuth consumer secret
 */
	protected $_consumerKey;

	protected $_consumerSecret;

/**
 * OAuth token and OAuth token secret (The user vars)
 *
 * @access protected
 * @var string $oauthToken The user-specific OAuth token
 * @var string $oauthTokenSecret The user-specific OAuth token secret
 */
	protected $_oauthToken;

	protected $_oauthTokenSecret;

/**
 * The OAuthConfig Class and class var
 *
 * @access protected
 * @var private $Oauth The HttpSocketOauth class var
 */
	protected $_Oauth;

	public function __construct($settings = array()) {
		parent::__construct();
		$this->initialize($settings);
		$this->_consumerKey = Configure::read('Twitter.consumerKey');
		$this->_consumerSecret = Configure::read('Twitter.consumerSecret');
	}

/**
 * Setup the counsumer key and consumer secret for the app.
 *
 * @access public
 * @param string $consumerKey OAuth consumer key of the Twitter app
 * @param string $consumerSecret OAuth consumer secret of the Twitter app
 */
	public function setupApp($consumerKey, $consumerSecret) {
		$this->_consumerKey = $consumerKey;
		$this->_consumerSecret = $consumerSecret;
		$content = array(
			'consumer_key' => $this->_consumerKey,
			'consumer_secret' => $this->_consumerSecret
		);
		if (!is_null(CakeSession::read('Twitter.Consumer'))) {
			CakeSession::delete('Twitter.Consumer');
		}
		CakeSession::write('Twitter.Consumer', $content);
	}

/**
 * Setup the oauth token and oauth token secret for the instance.
 *
 * @access public
 * @param string $consumerKey OAuth consumer key of the Twitter app
 * @param string $consumerSecret OAuth consumer secret of the Twitter app
 */
	public function setToken($token = null, $tokenSecret = null) {
		$this->_oauthToken = $token;
		$this->_oauthTokenSecret = $tokenSecret;
	}

/**
 * Connect app to twitter and let it authorize through the user.
 *
 * @param string $callbackUrl Url where Twitter should redirect after authorisation
 * @param string $action action from twitter api
 * @access public
 */
	public function connectApp($callbackUrl, $action='authorize') {
		$request = array(
			'uri' => array(
				'host' => 'api.twitter.com',
				'path' => '/oauth/request_token',
			),
			'method' => 'GET',
				'auth' => array(
				'method' => 'OAuth',
				'oauth_callback' => $callbackUrl,
				'oauth_consumer_key' => $this->_consumerKey,
				'oauth_consumer_secret' => $this->_consumerSecret,
			),
		);
		$response = $this->_Oauth->request($request);
		parse_str($response, $response);
		header("Location: http://api.twitter.com/oauth/$action?oauth_token={$response['oauth_token']}");
		exit();
	}

/**
 * Authenticate User with their twitter account.
 *
 * @param string $callbackUrl Url where Twitter should redirect after authentication
 * @access public
 */
	public function signIn($callbackUrl) {
		$this->connectApp($callbackUrl, 'authenticate');
	}

/**
 * The user authorisation, wich should be called after the user was redirected by Twitter
 * to your site again.
 *
 * @access public
 * @param string $oauthToken The token send back by Twitter to the callback url,
 * @param string $oauthVerifier: The verifier send back by Twitter to the callback url
 */
	public function authorizeTwitterUser($oauthToken, $oauthVerifier) {
		$request = array(
			'uri' => array(
				'host' => 'api.twitter.com',
				'path' => '/oauth/access_token',
				),
			'method' => 'POST',
			'auth' => array(
				'method' => 'OAuth',
				'oauth_consumer_key' => $this->_consumerKey,
				'oauth_consumer_secret' => $this->_consumerSecret,
				'oauth_token' => $oauthToken,
				'oauth_verifier' => $oauthVerifier,
				),
			);
		$response = $this->_Oauth->request($request);
		parse_str($response, $response);
		// Setup a new Twitter user
		if (isset($response['user_id'])) {
			$this->loginTwitterUser($response['oauth_token'], $response['oauth_token_secret'], $response['user_id'], $response['screen_name']);
		} else {
			$this->log('missing user_id in response');
			return false;
		}
	}

/**
 * Login the user to Twitter with his own and specific OAuth token and secret, if he isn't
 * already.
 *
 * @access public
 * @param string $oauthToken The oauth token
 * @param string $oauthTokenSecret The oauth secret
 */
	public function loginTwitterUser($oauthToken, $oauthTokenSecret, $userId = null, $screenName = null) {
		if (is_null(CakeSession::read('Twitter.User'))) {
			$this->_oauthToken = $oauthToken;
			$this->_oauthTokenSecret = $oauthTokenSecret;
			$newSession = array(
				'oauth_token' => $oauthToken,
				'oauth_token_secret' => $oauthTokenSecret,
				'user_id' => $userId,
				'screen_name' => $screenName,
			);
			CakeSession::write('Twitter.User', $newSession);
		}
	}

/**
 * Return the current oauth token and oauth token secret of the user and make
 * them usable in the controller. Be carefull in usage! (Secret and user-specific informations)
 *
 * @access public
 * @return array()
 * @param boolean $showFullProfile
 */
	public function getTwitterUser($showFullProfile = false) {
		$userKeys = array();
		if ($this->userStatus() == false) {
			$session = CakeSession::read('Twitter.User');
			if (!is_null($session)) {
				$userKeys['oauth_token'] = $session['oauth_token'];
				$userKeys['oauth_token_secret'] = $session['oauth_token_secret'];
			}
		} else {
			$userKeys['oauth_token'] = $this->_oauthToken;
			$userKeys['oauth_token_secret'] = $this->_oauthTokenSecret;
		}
		if ($showFullProfile == true) {
			$userKeys['profile'] = $this->accountVerifyCredentials();
		}
		return $userKeys;
	}

/**
 * Logout the current Twitter User (destroy Session `Twitter.User`)
 *
 * @access public
 */
	public function logoutTwitterUser() {
		$this->_oauthToken = null;
		$this->_oauthTokenSecret = null;
		if (!is_null(CakeSession::read('Twitter.User'))) {
			CakeSession::delete('Twitter.User');
		}
	}

/**
 * Initialize class
 */
	public function initialize($settings = array()) {
		$this->_Oauth = new HttpSocketOauth();
		if ($this->status() == false) {
			if ($this->appStatus() == false) {
				$consumerSession = CakeSession::read('Twitter.Consumer');
				if (!is_null($consumerSession)) {
					$this->_oauthToken = !empty($consumerSession['oauth_token']) ? $consumerSession['oauth_token'] : null;
					$this->_oauthTokenSecret = !empty($consumerSession['oauth_token_secret']) ? $consumerSession['oauth_token_secret'] : null;
				}
			}
			if ($this->userStatus() == false) {
				$oauthSession = CakeSession::read('Twitter.User');
				if (!is_null($oauthSession)) {
					$this->_oauthToken = $oauthSession['oauth_token'];
					$this->_oauthTokenSecret = $oauthSession['oauth_token_secret'];
				}
			}
		}
	}

/**
 * Status of the app (checks if consumer key and consumer secret are available)
 *
 * @access public
 * @return boolean
 */
	public function appStatus() {
		if ($this->_consumerKey != '' && $this->_consumerSecret != '') {
			return true;
		} else {
			return false;
		}
	}

/**
 * Status of the Twitter user (Checks if OAuth token and OAuth secret are available)
 *
 * @access public
 * @return boolean
 */
	public function userStatus() {
		if ($this->appStatus() == true) {
			if ($this->_oauthToken != '' && $this->_oauthTokenSecret != '') {
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

/**
 * Status of the whole Twitter connection
 *
 * @access public
 * @return boolean
 */
	public function status() {
		if ($this->appStatus() == true && $this->userStatus() == true) {
			return true;
		} else {
			return false;
		}
	}

/**
 * Return the auth array for the Twitter API methods
 *
 * @access private
 * @return array()
 */
	protected function _authArray() {
		return array(
			'method' => 'OAuth',
			'oauth_token' => $this->_oauthToken,
			'oauth_token_secret' => $this->_oauthTokenSecret,
			'oauth_consumer_key' => $this->_consumerKey,
			'oauth_consumer_secret' => $this->_consumerSecret
		);
	}

//==================== Twitter API methods

/**
 * Make a custom request on the Twitter API
 *
 * @access public
 * @return JSON or XML
 * @param string $method The request method (post, delete, get, put)
 * @param string $twitterMethodUrl The url of the API method (without 'api.twitter.com'),
 * e.g. /1/trends.json
 * @param array() $body The body of the api request. It has to be an valid array()
 */
	public function apiRequest($method, $twitterMethodUrl, $body) {
		$request = array();

		$method = strtoupper($method);
		if ($method == 'GET' || $method == 'POST' || $method == 'DELETE' || $method == 'PUT') {
			$request['method'] = $method;
		}

		if (substr($twitterMethodUrl, 0, 1) == '/') {
			$twitterMethodUrl = substr($twitterMethodUrl, 1, strlen($twitterMethodUrl));
		}
		$request['uri'] = array(
			'host' => 'api.twitter.com',
			'path' => $twitterMethodUrl
		);

		$request['auth'] = $this->_authArray();

		if (is_array($body)) {
			$body = array_change_key_case($body);
			if (array_key_exists('status', $body)) {
				if (strlen($body['status']) > 140) $body['status'] = substr($body['status'], 0, 137) . '...';
			} else if (array_key_exists('text', $body)) {
				if (strlen($body['text']) > 140) $body['text'] = substr($body['text'], 0, 137) . '...';
			}
			if ($method == 'GET') {
				$request['uri']['query'] = $body;
			} else {
				$request['body'] = $body;
			}
		}
		return $this->_Oauth->request($request);
	}

# Account Methods

/**
 * Returns an HTTP 200 OK response code and a representation of the
 * requesting user if authentication was successful;
 * returns a 401 status code and an error message if not.
 * Use this method to test if supplied user credentials are valid.
 *
 * @access public
 * @return array
 */
	public function accountVerifyCredentials() {
		return json_decode($this->apiRequest('get', '/1/account/verify_credentials.json', ''), true);
	}

/**
 * Returns the remaining number of API requests available to the requesting user
 * before the API limit is reached for the current hour. Calls to rate_limit_status
 * do not count against the rate limit. If authentication credentials are provided,
 * the rate limit status for the authenticating user is returned. Otherwise, the rate
 * limit status for the requester's IP address is returned.
 *
 * @access public
 * @return array()
 */
	public function accountRateLimitStatus() {
		return json_decode($this->apiRequest('get', '/1/account/rate_limit_status.json', ''), true);
	}

/**
 * Returns a list of the 20 most recent direct messages sent to the authenticating user.
 * The XML and JSON versions include detailed information about the sending and recipient users.
 *
 * NOTE: To use this methods your app needs 'Read, Write, and Direct messages'-Access
 *
 * @access public
 * @return array()
 * @param int $count The count how many messages should be shown (max. 200)
 * @param int $page Specifies the page of direct messages to retrieve
 */
	public function getDirectMessages() {
		return json_decode($this->apiRequest('get', '/1/direct_messages.json', ''), true);
	}

/**
 * Returns a list of the 20 most recent direct messages sent by the authenticating user.
 * The XML and JSON versions include detailed information about the sending and recipient users.
 *
 * @access public
 * @return array()
 */
	public function getDirectMessagesSent() {
		return json_decode($this->apiRequest('get','/1/direct_messages/sent.json', ''), true);
	}

/**
 * Sends a new direct message to the specified user from the authenticating user.
 * Requires both the user and text parameters. Request must be a POST.
 * Returns the sent message in the requested format when successful.
 *
 * @access public
 * @return array()
 * @param string $screenName The username of the recipient
 * (Must be a follower of the authenticating user)
 * @param text $text The message text. Shouldn't be longer than 140 chars
 */
	public function newDirectMessage($screenName, $text) {
		$body = array();
		if ($screenName != '' && $text != '') {
			$body['screen_name'] = strtolower($screenName);
			$body['text'] = $text;
		}
		return json_decode($this->apiRequest('post', '/1/direct_messages/new.json', $body), true);
	}

/**
 * Destroys the direct message specified in the required ID parameter.
 * The authenticating user must be the recipient of the specified direct message.
 *
 * @access public
 * @return array()
 * @param int $id An unique identifier number of the message.
 */
	public function destroyDirectMessage($id) {
		return json_decode($this->apiRequest('delete', '/1/direct_messages/destroy/' . $id . '.json', ''), true);
	}

/**
 * Add Favorite
 *
 * @link https://dev.twitter.com/docs/api/1/post/favorites/create/%3Aid
 * @access public
 * @return array()
 */
	public function newFavorite($id) {
		return json_decode($this->apiRequest('post', '/1/favorites/create/' . $id . '.json', ''), true);
	}

/**
 * Returns all ids of the friends form any user
 *
 * @access public
 * @return array()
 * @param string $screenName The username
 */
	public function getFriendsIds($screenName) {
		$body = array();
		$body['screen_name'] = strtolower($screenName);
		return json_decode($this->apiRequest('get', '/1/friends/ids.json', $body), true);
	}

/**
 * Returns all ids of the followers from any user
 *
 * @access public
 * @return array()
 * @param string $screenName The usernam
 */
	public function getFollowersIds($screenName) {
		$body = array();
		$body['screen_name'] = strtolower($screenName);
		return json_decode($this->apiRequest('get', '/1/followers/ids.json', $body), true);
	}

/**
 * Allows the authenticating users to follow the user specified in the ID parameter.
 * Returns the befriended user in the requested format when successful.
 * Returns a string describing the failure condition when unsuccessful.
 * If you are already friends with the user an HTTP 403 will be returned.
 *
 * @access public
 * @return array()
 * @param string $screenName The uername of the user to be followed
 */
	public function createFriendship($screenName) {
		if (!is_numeric($screenName)) {
			$body = array();
			$body['screen_name'] = strtolower($screenName);
			return json_decode($this->apiRequest('post', '/1/friendships/create.json', $body), true);
		}
	}

/**
 * Look at createFriendship()
 *
 * @access public
 * @return array()
 * @param int $id The unique identifier of the user
 */
	public function createFriendshipById($id) {
		if (is_numeric($id)) {
			$body = array();
			$body['user_id'] = $id;
			return json_decode($this->apiRequest('post', '/1/friendships/create.json', $body), true);
		}
	}

/**
 * Allows the authenticating users to unfollow the user specified in the ID parameter.
 * Returns the unfollowed user in the requested format when successful.
 * Returns a string describing the failure condition when unsuccessful.
 *
 * @access public
 * @retun array()
 * @param string $screenName The username of the user to unfollow
 */
	public function destroyFriendship($screenName) {
		if (!is_numeric($screenName)) {
			$body = array();
			$body['screen_name'] = strtolower($screenName);
			return json_decode($this->apiRequest('post', '/1/friendships/destroy.json', $body), true);
		}
	}

/**
 * Look at destoryFriendship()
 *
 * @access public
 * @return array()
 * @param int $id The unique identifier of the user to unfollow
 */
	public function destroyFriendshipById($id) {
		if (is_numeric($id)) {
			$body = array();
			$body['user_id'] = $id;
			return json_decode($this->apiRequest('post', '/1/friendships/destroy.json', $body), true);
		}
	}

/**
 * Tests for the existance of friendship between two users.
 * Will return true if user_a follows user_b, otherwise will return false.
 *
 * @access public
 * @return array()
 * @param string $user_a Screen name of user a
 * @param string $user_b Screen name of user b
 */
	public function friendshipExists($userA, $userB) {
		$body = array();
		$body['user_a'] = $userA;
		$body['user_b'] = $userB;
		return json_decode($this->apiRequest('get', '/1/friendships/exists.json', $body), true);
	}

/**
 * Returns a single status, specified by the id parameter below.
 * The status's author will be returned inline.
 *
 * @access public
 * @return array()
 * @param int $id The id of the tweet
 */
	public function showStatus($id) {
		if (is_numeric($id)) {
			return json_decode($this->apiRequest('get', '/1/statuses/show/' . $id . '.json', ''), true);
		}
	}

/**
 * Updates the authenticating user's status.
 * Requires the status parameter specified below. Request must be a POST.
 * A status update with text identical to the authenticating user's current
 * status will be ignored to prevent duplicates.
 *
 * @access public
 * @return array()
 * @param string $status The text wich should be posted as new status
 */
	public function updateStatus($status, $options = array()) {
		if ($status != null || $status != '') {
			$body = Set::merge(array(
				'status' => $status
				), $options);

			return json_decode($this->apiRequest('post', '/1/statuses/update.json', $body), true);
		}
	}

/**
 * Destroys the status specified by the required ID parameter.
 * The authenticating user must be the author of the specified status.
 *
 * @access public
 * @return array()
 * @param int $id The ID of the status wich should be destroyed
 */
	public function destroyStatus($id) {
		if (is_numeric($id)) {
			return json_decode($this->apiRequest('post', '/1/statuses/destroy/' . $id . '.json', ''), true);
		}
	}

/**
 * Retweet
 *
 * @link https://dev.twitter.com/docs/api/1/post/statuses/retweet/%3Aid
 * @access public
 * @return array()
 * @param string $status The text wich should be posted as new status
 */
	public function retweetStatus($id) {
		if ($id) {
			return json_decode($this->apiRequest('post', '/1/statuses/retweet/' . $id . '.json', ''), true);
		}
	}

/**
 * Returns the 20 most recent statuses from non-protected users
 * who have set a custom user icon. The public timeline is cached for 60 seconds
 * so requesting it more often than that is a waste of resources.
 *
 * @access public
 * @return array()
 */
	public function publicTimeline() {
		return json_decode($this->apiRequest('get', '/1/statuses/public_timeline.json', ''), true);
	}

/**
 * Returns the 20 most recent statuses posted by the authenticating user and that user's friends.
 * This is the equivalent of /timeline/home on the Web.
 *
 * @access public
 * @return array()
 */
	public function friendsTimeline() {
		return json_decode($this->apiRequest('get', '/1/statuses/friends_timeline.json', ''), true);
	}

/**
 * Returns the 20 most recent statuses, including retweets, posted by the authenticating
 * user and that user's friends. This is the equivalent of /timeline/home on the Web.
 *
 * @access public
 * @return array()
 */
	public function homeTimeline() {
		$body = 'include_rts=1';
		return json_decode($this->apiRequest('get', '/1/statuses/home_timeline.json', $body), true);
	}

/**
 * Returns the 20 most recent statuses posted from the authenticating user.
 * It's also possible to request another user's timeline via the id parameter.
 * This is the equivalent of the Web / page for your own user,
 * or the profile page for a third party.
 *
 * @access public
 * @return array()
 * @param int || string $param The ID or screen name of the user
 */
	public function userTimeline($param = null) {
		$body = array();
		if (is_numeric($param)) {
			$body['user_id'] = $param;
		} else if (!is_numeric($param)) {
			$body['screen_name'] = strtolower($param);
		} else if ($param == '' || $param == null) {
			// Return homeTimeline if $param is null
			return $this->homeTimeline();
		}
		return json_decode($this->apiRequest('get', '/1/statuses/user_timeline.json', $body), true);
	}

/**
 * Returns the 20 most recent mentions (status containing @username)
 * for the authenticating user.
 *
 * @access public
 * @return array()
 */
	public function mentionsTimeline() {
		return json_decode($this->apiRequest('get', '/1/statuses/mentions.json', ''), true);
	}

/**
 * Returns related results (to see conversation)
 * Note: This is undocumented/unsupported Twitter API
 *
 * @link https://dev.twitter.com/discussions/293
 * @link https://groups.google.com/d/msg/twitter-development-talk/zcNK54ojULg/rqmau1XD4HYJ
 */
	public function relatedResults($id) {
		$url = '/1/related_results/show/' . $id . '.json';
		return json_decode($this->apiRequest('get', $url, ''), true);
	}

/**
 * Returns extended information of a given user, specified by ID or screen name
 * as per the required id parameter. The author's most recent status will be returned inline.
 *
 * @access public
 * @return array()
 * @param int || string $param The ID or screen name of the user
 */
	public function showUser($param) {
		$body = array();
		$url = '/1/users/show.json';
		if (is_numeric($param)) {
			$body['user_id'] = $param;
			$url .= '?user_id=' . $param;
		} else {
			$body['screen_name'] = strtolower($param);
			$url .= '?screen_name=' . urlencode($param);
		}
		return json_decode($this->apiRequest('get', $url, $body), true);
	}

/**
 * Lookup users
 *
 * https://dev.twitter.com/docs/api/1/get/users/lookup
 *
 * $options:
 *   `user_id` string|array optional
 *   `screen_name` string|array optional
 */
	public function lookupUsers($options) {
		$body = array();
		$url = '/1/users/lookup.json';
		if (!isset($options['screen_name']) && !isset($options['user_id'])) {
			return false;
		}

		if (isset($options['screen_name'])) {
			if (is_array($options['screen_name'])) {
				$screenNames = join(',', $options['screen_name']);
			} else {
				$screenNames = $options['screen_name'];
			}
		}

		if (isset($options['user_id'])) {
			if (is_array($options['user_id'])) {
				$userIds = join(',', $options['user_id']);
			} else {
				$userIds = $options['user_id'];
			}
		}

		if (isset($userIds)) {
			$body['user_id'] = $userIds;
		} elseif (isset($screenNames)) {
			$body['screen_name'] = $screenNames;
		}

		return json_decode($this->apiRequest('get', $url, $body), true);
	}

}