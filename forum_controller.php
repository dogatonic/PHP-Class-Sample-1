<?php 

class ForumController extends ComponentController
{

	private $log;
	private $objOTApi;
	private $forumId;
	private $forumGroupId;
	public $forumCommander;

	public function __construct() {
			
		$this->log = Logger::getLogger(__CLASS__);
		// 	$this->log->info(" __construct");
		$this->forumCommander = new ForumCommander();
			
	}
	
	public function getForum($params) {

		$this->log->info("getForum heynow");

		$this->layout = "ajax";

		$this->log->info("params [" . print_r($params, true) . ']');
		// Potential Prameters:
		// 0 : null
		// 1 : forumSlug
		// 2 : 'admin'
		// 3 : a user Id
		
		$forumSlug = (isset($params[1])) ? $params[1] : '';// this is the forum slug which will eventually lead to the content of the forum
		if($forumSlug == 'openforum'){
			$forumSlug = '';
		}
		
		$forumModel = $this->getForumModelFromSlug($forumSlug);
		$this->forumId = ($forumModel != false) ? $forumModel->getId() : null;

		// If we did not find the forum, and we are not in an openforum, return false. This will ensure that poorly typed URLs don't just end up on openforum.
		if($forumModel == false && strlen($forumSlug)){
			$this->log->info("That forum could not be found.");
			$this->ajaxErr = 1;
			$this->pushAjaxMessage("That forum could not be found.");
			return false;
		} else {
			$this->log->info("slug [" . $forumSlug . ']');
			$this->log->info("sluglen [" . strlen($forumSlug) . ']');
		}
		
		$userModel = new UserModel();
		$userModel->initialize();
		$me = $userModel->getUser();
		$uId = $me->getId();
		$forumGroupFindUserId = $uId;
		// 		$this->log->info("me id = " . $uId);
		
		if(isset($params[2]) && $params[2] == 'admin'){
			if( isset($params[3]) ){
				// use the provided userId to load the forumGroup that the Admin wants to observe
				$forumGroupFindUserId = $params[3];
			}
		}
		
		$this->objOTApi = new OpenTokSDK(API_Config::API_KEY, API_Config::API_SECRET);

		$forumGroupModel = new ForumGroupModel();
		$customerModel = new CustomerModel();
		$arrForumUsers = array();

		if($forumG = $forumGroupModel->findOne(array('value.customerIdSlots' => $forumGroupFindUserId))){// find forum group by user id
			$this->forumGroupId = $forumG['id'];
			$this->log->info("forumGroupModel id  [" . $this->forumGroupId . ']');
			$forumScheduleModel = new ForumScheduleModel();
			
			if($forumModel != false){
				// Check the forumSChedule to see if it's timeslot fits the time right now!
				if($forumS = $forumScheduleModel->findOne(array( 'value.forumGroupId' => $this->forumGroupId, 'value.forumId' => $this->forumId ))){// find forum schedule by forum group id
					$this->log->info("Found a forum SCHEDULE that matches forumId and forumGroupId");
					$forumScheduleModel->setId($forumS['id']);
					$forumScheduleModel->load();
					if(time() >= $forumScheduleModel->forumStartTime){
						if(time() < ($forumScheduleModel->forumStartTime + $forumScheduleModel->forumDuration) ){
							$this->log->info("Found a forum SCHEDULE that is ready now");
						} else {
							$this->log->info("Your forum has already occured.");
							$this->ajaxErr = 1;
							$this->pushAjaxMessage("Your forum has already occured.");
							return false;
						}
					} else {
						$timeUntilStart = $forumScheduleModel->forumStartTime - time();
						$stringTimeUntilStart = $this->timeToString($this->secondsToTime($timeUntilStart));
						$this->log->info("Your forum will be ready in " . $stringTimeUntilStart . '.');
						$this->ajaxErr = 1;
						$this->pushAjaxMessage("Your forum will be ready in " . $stringTimeUntilStart . '.');
						return false;
					}
				} else {
					$this->log->info("Could not find a forum SCHEDULE that matches forumId and forumGroupId");
					$this->ajaxErr = 1;
					$this->pushAjaxMessage("Could not find a forum scheduled for your group.");
					return false;
				}
			}
			
			$forumInstanceModel = new ForumInstanceModel();
			// obtain Customer info from mongo
			foreach($forumG['customerIdSlots'] as $thisUserId){
				if($findUser = $customerModel->findOne(array('value.id' => $thisUserId))){
					$arrForumUsers[$findUser['id']] = $findUser;
				}
			}
				
			// here, we will reorder the 'customerIdSlots' array, starting with me first, those after me next, and those before me after that.
			// i.e. if I am 'S' in [N, E, S, W], the new order will be [S, W, N, E]
			$arr = $forumG['customerIdSlots'];
			foreach($arr as $key=>$val){
				if($val !== $uId){
					array_push($arr, $val);
					unset($arr[$key]);
				} else break;
			}
			$arrForumGroupMembers = array_values($arr);
			
			
			########################################################
			# do I have a forumInstance yet for this
			########################################################
			if($forumI = $forumInstanceModel->findOne(array( 'value.forumGroupId' => $this->forumGroupId, 'value.forumId' => $this->forumId ))){// find forum instance by forum group id
				$this->log->info("Found forumInstanceModel openTokSessionId [". $forumI['openTokSessionId'] . ']');
				$forumInstanceModel->setId($forumI['id']);
				$forumInstanceModel->load();
				
			} else {// not found. make new forum instance and save it to mongo
				$this->log->info("forumInstanceModel NOT FOUND forumId = [" . $this->forumId . ']');
				
				try {
// 					$forumInstanceModel->retrieveNewSessionId($this->objOTApi);
					$forumInstanceModel->openTokSessionId = $this->retrieveOTsessionID();
					$forumInstanceModel->forumGroupId = $this->forumGroupId;
// 					$forumInstanceModel->memberOpens = array_fill_keys($forumG['customerIdSlots'], 0);
					$forumInstanceModel->startDateTime = time();
					$forumInstanceModel->createdTimeStamp = time();
					$forumInstanceModel->nextUserHistory = array();
					$id = $forumInstanceModel->uuid();
					$forumInstanceModel->setId($id);
					$forumInstanceModel->save();
// 					$forumInstanceModel->load();
// 					$forumI = $forumInstanceModel->findOne(array('value.forumGroupId' => $this->forumGroupId));

				} catch (OpenTokException $exception) {// OpenTok SDK failure. Could not make new session Id
					$this->log->info("Errnow to get OT session[" . (time() - $startGetSession) . ' seconds]');
					$this->log->info("THROWN! We could start a forum without an OT session, but why? : " . $exception->getMessage());
					$this->ajaxErr = 1;
					$this->pushAjaxMessage("Couldn't make new OTsession.");
					return false;
				}
			}  // done making new forumInstanceModel
			
			########################################################
			# check for message for this Instance and send them down the line
			########################################################			
			$arrMessages = array();
			$forumMessageModel = new ForumMessageModel();
			if($cursMessage = $forumMessageModel->find(array( 'value.forumInstanceId' => $forumInstanceModel->id))){
				foreach($cursMessage as $objMessage){
					$arrMessages[] = $objMessage['value'];
				}
			}
			
		} else {// Did not find user in any Forum Groups
			$this->log->info("Did not find user in any Forum Groups");
			$this->ajaxErr = 1;
			$this->pushAjaxMessage("Couldn't find your customer ID in forum groups");
			return false;
		}
		
		
		########################################################
		# if I have a forumModel I need a wheel
		########################################################
		if($forumModel != false){
			// if I have a forum model then I have a forum that I shouls start a wheel for.
			// $this->createForumProcess($forumInstanceModel->getId());
			
			$forumWheelModel = new ForumWheelModel();
			
			$this->log->info("Hey starting a forumWheel here");
			
			$forumWheelModel->createForumWheelProcess($forumInstanceModel->getId());
			
			if (!isset($forumInstanceModel->forumId)) {
				$this->log->info("attaching this forumId to this forumInstanceModel");
				$forumInstanceModel->forumId = $forumModel->getId();
				$forumInstanceModel->save();
			} else {
				$this->log->info("NOT attaching this forumId to this forumInstanceModel because forumInstanceModel->forumId is set ".$forumInstanceModel->forumId);
			}
			
			$this->log->info("Setting mode 1:  means that we should have a wheel to go along with this forumInstance.");
			
			$forumModel->mode = 1;
		} else {
			
			$this->log->info("Setting mode 2: means we are in an open forum and no wheel is requested.");
			
			$forumModel->mode = 2;
// 			$this->log->info("heynow [" . $forumModel->mode . ']');
		}
		
		
		
		########################################################		
		// TUCKER: this next bit was in the old "getForumModel()" method which has now been rearranged
		if ($forumInstanceModel->flowStatus == "" || $forumInstanceModel->flowStatus == "open") {

			$this->log->info("forumInstanceModel->flowStatus : setting the attached flowStatus");

			$forumInstanceModel->flowStatus = "attached";
			$forumInstanceModel->save();

		} else {
			$this->log->info("this forumInstanceModel flowStatus seems to be attached or some later state");
		}

// 		$forumModel->openTokSessionId = $forumI['openTokSessionId'];
		$forumModel->openTokSessionId = $forumInstanceModel->openTokSessionId;
// 		$forumModel->forumInstanceId = $forumI['id'];
		$forumModel->forumInstanceId = $forumInstanceModel->getId();
		$forumModel->users = $arrForumGroupMembers;
		$forumModel->usersInfo = $arrForumUsers;
		$forumModel->forumMessages = $arrMessages;
		$forumModel->openTokApiKey = API_Config::API_KEY;

		// 		If we have gotten this far, we have a Forum Instance
		// 		make a token
		
		try {
			$forumModel->openTokToken = $this->objOTApi->generateToken($forumModel->openTokSessionId, RoleConstants::PUBLISHER, null, $uId);	
		} catch (OpenTokException $exception) {// opentok SDK failure
			$this->log->info("THROWN! OpenTok Exception: Failed to get Token: " . $exception->getMessage());
			$this->ajaxErr = 1;
			$this->pushAjaxMessage("Couldn't make new OT token.");
			return false;
		}

		return $forumModel;
			
	}

	private function retrieveOTsessionID(){
		if(0){// find session id in mongo
			
		} else {// generate an OT session and request sessionId
			$startGetSession = time();
			$this->log->info("Startnow to get OT session[" . ']');
			$session = $this->objOTApi->createSession();
			$this->log->info("Endnow to get OT session[" . (time() - $startGetSession) . ' seconds]');
			$returnId = $session->getSessionId();
		}
		
		return $returnId;
	}
	
	public function getForumModelFromSlug($forumSlug) {

		$this->log->info("getForumModel for forumSlug [" . $forumSlug . ']');

		$forumModel = new ForumModel();

		// get the id from the slug
		$slugModel = new SlugModel();
		$slugModel->setId(slugModel::buildId($forumSlug, "ForumModel"));
		$slugModel->load();

		if ($slugModel->refId) {

			$this->log->info("loaded slugModel refId ".$slugModel->refId);
			$forumModel->setId($slugModel->refId);

			if ($forumModel->load()) {

			// 	$this->log->info("loaded forumModel  [" . print_r($forumModel, true) . ']');
				$this->log->info("forumModel id  [" . $forumModel->getId(). ']');
				$this->log->info("forumModel title  [" . $forumModel->title. ']');
				$this->log->info("forumModel slug  [" . $forumModel->slug. ']');
				$this->log->info("forumModel type  [" . $forumModel->type. ']');
				$this->log->info("forumModel forumSegments [" . print_r($forumModel->forumSegments, true) . ']');

				// get the forum header (type, ui rules, time position (not started, running, stopped, ended))
					
				// get the segment structure
				foreach ($forumModel->forumSegments as $k) {
					$segment = new ForumSegmentModel();
					$segment->setId($k);
					$segment->load();

					// $this->log->info("loaded segment [" . print_r($segment, true) . ']');
					// $this->log->info("segment id  [" . $segment->getId(). ']');

					$forumModel->segmentsInfo[$k] = $segment;

				}	

			} else {
				$this->log->info("could not load forumModel ");
			}

		} else {// end 	$slugModel
			$this->log->info('(' . __LINE__ . ')' . "Could not load slugModel ");
			return false;
		}

		return $forumModel;

	}

	// this method has a route. this method will execute a method, referenced by a string in $params[1]
	// Current expected methods: setActiveUser, setMemberUpdate, setMemberWatchedVideo
	public function instanceStatus($params) {
		
		$this->log->info("instanceStatus");
		
		$this->layout = "ajax";
		$command = $params[1];
		
		$this->log->info("instanceStatus command ".$command);

		if(method_exists($this, $command)){
			try{
				// DLA: warning warning warning tainted string used to select a fuction
				// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
				// TUCKER: well, that may be true, but it's not that different than an exposed AJAX route, this method was useful early on when...
				// we had many more methods to call and I wanted fewer routes. We could sunset this if we want. 4/25/2014
				$response = $this->{$command}($params);
				return $response;
			} catch(Exception $e){
				$this->log->info("THROWN! " . $e->getMessage());
				$this->ajaxErr = 1;
				$this->pushAjaxMessage("exception thrown");
			}
		} else {
			$this->log->info("Method does not exist ");
			$this->ajaxErr = 1;
			$this->pushAjaxMessage("Method does not exist");
		}
	}
 
	// This method has a Route. It is looking for commands used to control the forumProcess.
	public function controlCommands($params){
		
		$this->log->info("controlCommands");
		
		$this->layout = "ajax";
		$controlCommand = $params[1];
		$forumInstanceId = $params[2];

		switch($controlCommand){
			case 'forumStart':
			case 'forumPause':
			case 'forumPrev':
			case 'forumNext':
			case 'forumBailOut':
			case 'forumRating':
			case 'forumEnd':			
				$this->sendCommandToZMQ($forumInstanceId, $controlCommand);
				break;
			default:
				//do defult
		}
		$objReturn = new stdClass;
		$objReturn->controlCommand = $controlCommand;
		return $objReturn;
	}
	
	// ==============================================================================================================================
	// private function intended for a lib should the forumProcess need these functions #1
	// ==============================================================================================================================
	private function isForumRunning ($forumInstanceId) {

		$this->log->info("getForumProcessStatus ");

		$reply = false;
		
		$this->log->info(">>>>>>>> forumInstanceId [".$forumInstanceId . ']');
		
		
		$forumWheelModel = new ForumWheelModel();
	
		if ($forumWheelModel->loadByForumInstanceID($forumInstanceId)) {
			
// 				$this->log->info("have forumWheelArray print_r ".print_r($forumWheelModel, true));
				$this->log->info("have forumWheelArray socketAddress ".$forumWheelModel->socketAddress);
				
		}	
		
		// ok need to zmq for a process of this $forumInstanceId and get its status back
		$context = new ZMQContext();
	
		//  Socket to talk to server
		$this->log->info("Connecting to rep server server\n");
		
		$requester = new ZMQSocket($context, ZMQ::SOCKET_REQ);
		$requester->setSockOpt(ZMQ::SOCKOPT_LINGER, 0);
		// $requester->setSockOpt(ZMQ::SOCKOPT_SUBSCRIBE, "forummessage");
		
		$requester->connect($forumWheelModel->socketAddress);
	
		$this->log->info("Connected\n");
		
	    $requester->send("forumStatus:".$forumInstanceId);
	
	    $read = $write = array();
	    $poll = new ZMQPoll();
	    $poll->add($requester, ZMQ::POLL_IN);
	    
		$events = $poll->poll($read, $write, 1000);
	            
            if ($events) {
				$reply = $requester->recv();
    			$this->log->info("############# Received reply: ".$reply);
    			
    			$reply = true;
    			
            } else {
            	$this->log->info("############# no reply: ");	
            	
            }
    

		
		return $reply;
	}
	

	
	private function sendCommandToZMQ ($forumInstanceId, $command) {
		// NOTE!!!!!!!!!!: the command must beign with "forum", i.e. "forumUpdateMembers"
		$this->log->info("sendCommandToZMQ [" . $command . ']');

		$reply = false;
		
		$this->log->info(">>>>>>>> forumInstanceId [".$forumInstanceId . ']');
		
		$forumWheelModel = new ForumWheelModel();
	
		if ($forumWheelModel->loadByForumInstanceID($forumInstanceId)) {
			
// 				$this->log->info("have forumWheelArray print_r ".print_r($forumWheelModel, true));
				$this->log->info("have forumWheelArray socketAddress ".$forumWheelModel->socketAddress);
				
		} else{
				$this->log->info("didnt find the forumWheelModel->socketAddress");
				
		}
		
		// ok need to zmq for a process of this $forumInstanceId and get its status back
		$context = new ZMQContext();
	
		//  Socket to talk to server
		$this->log->info("Connecting to rep server server\n");
		
		$requester = new ZMQSocket($context, ZMQ::SOCKET_REQ);
		$requester->setSockOpt(ZMQ::SOCKOPT_LINGER, 0);
		// $requester->setSockOpt(ZMQ::SOCKOPT_SUBSCRIBE, "forummessage");
		
		$requester->connect($forumWheelModel->socketAddress);
	
		$this->log->info("Connected\n");
		
	    $requester->send( $command . ":" . $forumInstanceId);
	
	    $read = $write = array();
	    $poll = new ZMQPoll();
	    $poll->add($requester, ZMQ::POLL_IN);
	    
		$events = $poll->poll($read, $write, 1000);
	            
            if ($events) {
				$reply = $requester->recv();
    			$this->log->info("!############# Received reply: ".$reply);
    			
    			$reply = true;
    			
            } else {
            	$this->log->info("!############# no reply: ");	
            	$reply = false;
            }
		
		return $reply;
	}

	// ==============================================================================================================================
	// private function intended for a lib should the forumProcess need these functions #2
	// ==============================================================================================================================
	
	public function submitRatings($params) {

		$this->log->info("submitRating");

		$this->layout = "ajax";

		$instanceId = $params[1];
		$customerId = $params[2];
		$objRatings = json_decode($_POST['ratings']);

		$this->log->info("instanceId [" . $instanceId . ']');
		$this->log->info("segmentId [" . $customerId . ']');
		$this->log->info("ratings obj [" . print_r($objRatings, true) . ']');

		$forumInstanceModel = new ForumInstanceModel();
		if($forumI = $forumInstanceModel->findOne(array('value.id' => $instanceId))){
			// this user has submitted their ratings, we will update the forumInstance so the Wheel can check for a ratings quorum
			$forumInstanceModel->setId($forumI['id']);
			$forumInstanceModel->load();
			$forumInstanceModel->attendingMembers[$customerId]['hasSubmittedRating'] = true;
			$forumInstanceModel->save();
			
			// Save each rating to Mongo
			foreach($objRatings as $uid=>$ratingValue){
					
				$this->log->info("uid [" . $uid . '] value=' . $ratingValue);
				$forumRatingModel = new ForumRatingModel();
				$forumRatingModel->ratorId = $customerId;
				$forumRatingModel->ratorRole = 'forumbuddy';
				$forumRatingModel->customerId = $uid;
				$forumRatingModel->productInstanceId = '42';
				$forumRatingModel->forumInstanceId = $instanceId;
				$forumRatingModel->rating = $ratingValue;
					
				$id = $forumRatingModel->uuid();
				$forumRatingModel->setId($id);
					
				$forumRatingModel->save();
			}
			
			// check if all attendingMembers have submitted, if so, notify the trigger
			
			$countSubmitted = 0;
			foreach($forumInstanceModel->attendingMembers as $arrData){
				if($arrData['hasSubmittedRating'] == true){
					$countSubmitted++;
				}
			}
			if($countSubmitted == count($forumInstanceModel->currentMembers)){
				// notify the trigger that we have acheieved ratingComplete
// 				$this->sendCommandToZMQ($instanceId, 'forumRatingsComplete');
				$this->sendCommandToZMQ($instanceId, 'forumNext');
			}
			
		} else {
			$this->log->info('(' . __LINE__ . ')' . "Instance not found! ");
			$this->ajaxErr = 1;
			$this->pushAjaxMessage("Instance not found!");
			return false;
		}
		
		$this->ajaxMsg[0] = "ratings accepted";
		return $objRatings;

	}

	public function submitBid($params) {
		
		$this->log->info("submitBid");
		
		$this->layout = "ajax";
		
		$instanceId = $params[1];
		$segmentId = $params[2];
		$customerId = $params[3];
		$bidValue = $params[4];
		
		$this->log->info("instanceId [" . $instanceId . ']');
		$this->log->info("segmentId [" . $segmentId . ']');
		$this->log->info("customerId [" . $customerId . ']');
		$this->log->info("bidValue [" . $bidValue . ']');
		
// 		if ($bidValue == 0) {
// 				$this->ajaxErr=1;
// 				$this->ajaxMsg[0] = "I cannot accept a bid of 0";				
// 				return false;
// 		}
		
		if ($segmentId == null || $segmentId == 'null') {
				$this->ajaxErr=1;
				$this->ajaxMsg[0] = "I cannot accept a bid without a segment id.";				
				return false;
		}
		
		$forumBidModel = new ForumBidModel();
		if ($cursor = $forumBidModel->find(array("value.forumInstanceId" => $instanceId, "value.forumSegmentId" => $segmentId, "value.customerId" => $customerId))) {
			$this->log->info("have bids");
			foreach ($cursor as $currentBid) {
				$this->log->info("forumBidModel to remove ".print_r($currentBid['id'], true));
				$forumBidModel->remove(array('id' => $currentBid['id']));
			}
		}
		
		
		$forumBidModel = new ForumBidModel();
		
		$id = $forumBidModel->uuid();
		$forumBidModel->setId($id);
		
		$forumBidModel->forumInstanceId = $instanceId;
		$forumBidModel->forumSegmentId = $segmentId;
		$forumBidModel->customerId = $customerId;
		$forumBidModel->bid = $bidValue;
		
		$id = $forumBidModel->uuid();
		$forumBidModel->setId($id);
		
		$forumBidModel->save();
		
		$this->ajaxMsg[0] = "Bid accepted";
		return $forumBidModel;

	}
	
	public function submitMessage($params) {
		$this->log->info("submitMessage");

		$this->layout = "ajax";

		$instanceId = $params[1];
		$customerId = $params[2];
		$userName = $params[3];
		
		$filterArgs = array("message" => FILTER_SANITIZE_STRING);
		if ($filteredPOST = filter_input_array(INPUT_POST, $filterArgs)) {
			$sMessage = $filteredPOST['message'];
		} else {
			$this->ajaxErr=1;
			$this->pushAjaxMessage("Invalid message.");
			return false;
		}
		
// 		$sMessage = strip_tags($_POST['message'],'<span>');

		$this->log->info("instanceId [" . $instanceId . ']');
		$this->log->info("message [" . $sMessage . ']');
		$this->log->info("customerId [" . $customerId . ']');
		$this->log->info("customerName [" . $userName . ']');

		if ($sMessage == "") {
// 			$this->ajaxErr=1;
// 			$this->ajaxMsg[0] = "Empty message";
			return false;
		}

		$forumInstanceModel = new ForumInstanceModel();
		if($forumI = $forumInstanceModel->findOne(array('value.id' => $instanceId))){
			$forumInstanceModel->setId($forumI['id']);
			$forumInstanceModel->load();
			$arrSendToUsers = array_merge(array_keys($forumInstanceModel->currentMembers), array_keys($forumInstanceModel->currentAdmins) );
		} else {
			$this->log->info("Instance not found");
		}
		
// 		$this->log->info(print_r($arrSendToUsers, true));
		
		$forumMessageModel = new ForumMessageModel();

		$id = $forumMessageModel->uuid();
		$forumMessageModel->setId($id);

		$forumMessageModel->forumInstanceId = $instanceId;
		$forumMessageModel->customerId = $customerId;
		$forumMessageModel->senderName = $userName;
		$forumMessageModel->senderMessage = $sMessage;
		$forumMessageModel->createdTimeStamp = time();
		$forumMessageModel->save();
		
		$data = array (
				"activeUserId"  => $customerId,
				"command" => "message",
				"messageText"   => $sMessage
		);
		
		$forumCommandData = array();
		$forumCommandData['command'] = 'userMessageReceived';
		$forumCommandData['senderName'] = $userName;
		$forumCommandData['senderMessage'] = $sMessage;
		$this->forumCommander->sendLiveForumCommandToClient($arrSendToUsers,$forumCommandData);

		$this->ajaxMsg[0] = "Message accepted";
		return $forumMessageModel;
		return true;
		
	}
        
	// this method is associated to the "instanceStatus" method, which is run by a Route
	public function setMemberUpdate($params) {
		
		$this->log->info( "setMemberUpdate ".print_r($params, true));
		$bIsAdmin = false;
		
		$instanceId = $params[2];
		$status = $params[3]; // created or destroyed
		$memberId =  $params[4]; // the userId
		$forumQuorumMinimum = $params[5]; // the quorum minimum for this forum
		$userFirstName =  $params[6]; // the userName
		$bSendUpdateToTrigger = true;
		$forumInstanceModel = new ForumInstanceModel();
		if($forumI = $forumInstanceModel->findOne(array('value.id' => $instanceId))){
 			
			$this->log->info("found instance");
			$forumInstanceModel->setId($forumI['id']);
			$forumInstanceModel->load();
			
			$bSendUpdateViaLive = false;
			switch($status){
				case 'zero':
					$forumInstanceModel->currentMembers = array();
					break;
				case 'created':
					$forumInstanceModel->currentMembers[$memberId] = $userFirstName;
					if(!isset($forumInstanceModel->attendingMembers[$memberId])){
						// 						$this->log->info("CREATE an ATTENDING MEMBER");
						$forumInstanceModel->attendingMembers[$memberId] = array('hasSubmittedRating'=>false, 'timesAsOpener'=>0, 'timesAsPresenter'=>0);
// 						$bSendUpdateViaLive = true;
					} else {
						$this->log->info("apparrently the attendingMember IS set");
					}
					$bSendUpdateViaLive = true;
					break;
				case 'destroyed':
					if(isset($forumInstanceModel->currentMembers[$memberId])){
						unset($forumInstanceModel->currentMembers[$memberId]);
						$bSendUpdateViaLive = true;
					}
					break;
				case 'admin':
					$forumInstanceModel->currentAdmins[$memberId] = time();
					$bSendUpdateViaLive = true;
					$bIsAdmin = true;
					if(!is_null($forumInstanceModel->forumId)){// TUCKER: NOTE!! if we are not on an openForum, we must let the Wheel know we have an admin to send signals to
						$this->sendCommandToZMQ($instanceId, 'forumAdminOn');
					}
					break;
			}
			
			$forumInstanceModel->save();

			if($bSendUpdateViaLive){
				// TUCKER: this was in the Wheel. Now moving it here.
				$forumCommandData = array();
				$forumCommandData['command'] = 'memberUpdateFromController';
				$forumCommandData['forumInstanceId'] = $instanceId;
					
				if(count($forumInstanceModel->currentMembers)  >= $forumQuorumMinimum){
					// we have enough people, controls should be on
					$forumCommandData['message'] = 'Please click the Start button to begin.';
					$forumCommandData['controlsEnabled'] = true;
					// 				$responder->send("forumUpdateMembers:ok");
						
				} elseif(count($forumInstanceModel->currentMembers)  < $forumQuorumMinimum){
					$needed = $forumQuorumMinimum - count($forumInstanceModel->currentMembers);
					$userWord = ($needed == 1)? 'member' : 'members';
					$forumCommandData['message'] = 'This forum needs ' . $needed . ' more ' . $userWord . ' to begin.';
					$forumCommandData['controlsEnabled'] = false;

					// 				TUCKER: I am taking this out. We shall not pause a forum! 2013/09/30
					// 				$commandParams = array(null, 'forumPause', $instanceId);

				} else {
					$forumCommandData['message'] = 'Please debug this.';
					$forumCommandData['controlsEnabled'] = false;
				}

				$this->log->info("SEND MY COMMAND NOW ");
					
				$arrSendToUsers = array_merge(array_keys($forumInstanceModel->currentMembers), array_keys($forumInstanceModel->currentAdmins) );
				if($bIsAdmin){
					$arrSendToUsers = array($memberId);
				}
				$forumCommandData['currentMembers'] = array_keys($forumInstanceModel->currentMembers);
				$this->forumCommander->sendLiveForumCommandToClient($arrSendToUsers, $forumCommandData);

// 				$this->log->info("Live Send to Client SEND TO [" . print_r($arrSendToUsers, true) . ']');
					
			}

			$this->log->info('(' . __LINE__ . ')' . "Instancefound! [" . $forumI['id'] . ']');
			
			return $forumInstanceModel;
			
			
		} else {
			$this->log->info('(' . __LINE__ . ')' . "Instance not found! ");
			$this->ajaxErr = 1;
			$this->pushAjaxMessage("Instance not found!");
			return false;
		}
	}

	
	// this method is associated to the "instanceStatus" method, which is run by a Route
	public function setMemberWatchedVideo($params) {
	
		$this->log->info( "setMemberWatchedVideo ".print_r($params, true));
	
		$instanceId = $params[2];
		$status = $params[3]; // finished
		$memberId =  $params[4]; // the userId
		$userFirstName =  $params[5]; // the userName
		$forumInstanceModel = new ForumInstanceModel();
		if($forumI = $forumInstanceModel->findOne(array('value.id' => $instanceId))){
	
			$this->log->info("found instance");
			$forumInstanceModel->setId($forumI['id']);
			$forumInstanceModel->load();
				
			switch($status){
				case 'finished':
					$forumInstanceModel->currentMembersWatchedVideo[$memberId] = $userFirstName;
					break;
			}
				
			$forumInstanceModel->save();
			
			if(count($forumInstanceModel->currentMembersWatchedVideo)  >= count($forumInstanceModel->currentMembers)){
				$this->log->info( "heynow All video watched, send the NEXT! command");
				$this->sendCommandToZMQ($instanceId, 'forumNext');
			} else {
				$this->log->info( "heynow All video watched, send the NEXT! command");
			}
				
			return $forumInstanceModel;
							
		} else {
			$this->log->info('(' . __LINE__ . ')' . "Instance not found! ");
			$this->ajaxErr = 1;
			$this->pushAjaxMessage("Instance not found!");
			return false;
		}
	}
	
	
	
	
	private function setActiveUser($params) {
		
// 		TUCKER: I offloaded this method to the ForumCommander so it can be called by the Trigger Process
		return $this->forumCommander->setActiveUser($params);

	}

	private function timeToString($arrTime){
		$s = '';
		$s .= ($arrTime['d'] > 0) ? $arrTime['d'] . ' day' : '';
		$s .= ($arrTime['d'] > 1) ? 's' : '';
		$s .= ($arrTime['h'] > 0) ? ' ' . $arrTime['h'] . ' hour' : '';
		$s .= ($arrTime['h'] > 1) ? 's' : '';
		$s .= ($arrTime['m'] > 0) ? ' ' . $arrTime['m'] . ' minute' : '';
		$s .= ($arrTime['m'] > 1) ? 's' : '';
		return $s;
	}
	
	private function secondsToTime($inputSeconds) {
	
		$secondsInAMinute = 60;
		$secondsInAnHour  = 60 * $secondsInAMinute;
		$secondsInADay    = 24 * $secondsInAnHour;
	
		$days = floor($inputSeconds / $secondsInADay);
		$hourSeconds = $inputSeconds % $secondsInADay;
		$hours = floor($hourSeconds / $secondsInAnHour);
		$minuteSeconds = $hourSeconds % $secondsInAnHour;
		$minutes = floor($minuteSeconds / $secondsInAMinute);
		$remainingSeconds = $minuteSeconds % $secondsInAMinute;
		$seconds = ceil($remainingSeconds);
	
		// return the final array
		$arrTimes = array(
				'd' => (int) $days,
				'h' => (int) $hours,
				'm' => (int) $minutes,
				's' => (int) $seconds,
		);
		return $arrTimes;
	}
	
}


?>