<?php
App::uses('AppController', 'Controller');
/**
 * Programs Controller
 *
 * @property Program $Program
 */
class ProgramsController extends AppController {

/**
 * index method
 *
 * @return void
 */
	public function index() {
        $channels = $this->Program->Channel->find('list');
        $channels = array(''=>'Select Channel')+$channels;
		$this->Program->recursive = 0;
        $filters = array();

        $this->paginate['Program']['limit'] = 10;
        $this->paginate['Program']['order'] = 'Program.id DESC';

        if($this->request->params['named']['q']){
            App::uses('Sanitize', 'Utility');
            $q = Sanitize::clean($this->request->params['named']['q']);
            $this->paginate['Program']['conditions']['OR'] = array(
                'Program.name LIKE' => '%' . $q . '%',
                'Program.production_house LIKE' => '%' . $q . '%',
                'Program.twitter LIKE' => '%' . $q . '%',
                'Program.fb_page_url LIKE' => '%' . $q . '%',
                'Program.jockey_user_name LIKE' => '%' . $q . '%'
            );

            $filters['q'] = $this->request->params['named']['q'];
        }

        if($this->request->params['named']['channel']){
            $this->paginate['Program']['conditions']['channel_id'] = $this->request->params['named']['channel'];

            $filters['channel'] = $this->request->params['named']['channel'];
        }
		
        if($this->request->params['named']['broadcast']){
            $this->paginate['Program']['conditions']['broadcasting_date'] = date('Y-m-d',strtotime($this->request->params['named']['broadcast']));

            $filters['broadcast'] = $this->request->params['named']['broadcast'];
        }

		$this->set('programs', $this->paginate('Program'));
        $this->set(compact('channels','filters'));
	}


/*
 * assigned_programs list
 *
 *
 */

        public function assigned_programs(){
		$user = $this->Session->read('Auth.User'); CakeLog::write('userinfo', print_r($user, 1));
        $current_user_name = $user['name']; CakeLog::write('userinfo', print_r($current_user_name, 1));
        $assigned_programs=$this->Program->find('all', array(
                'conditions'=>array(
                        'Program.jockey_user_name'=>$user['name'],
						'Program.broadcasting_date >='=>date('Y-m-d')
                        ),
                  'recursive'=>-1
        ));
        CakeLog::write('assigned_programs', print_r($assigned_programs, 1));
        $this->set('programs', $assigned_programs);
        }

/**
 * view method
 *
 * @throws NotFoundException
 * @param string $id
 * @return void
 */
	public function view($id = null) {
		$this->Program->id = $id;
		if (!$this->Program->exists()) {
			throw new NotFoundException(__('Invalid program'));
		}
		$this->set('program', $this->Program->read(null, $id));

		 //analytics per program basis
		$stat_program = Classregistry::init('AppUserProgramAdsStat')->find('all', array(
				'conditions'=>array(
						'AppUserProgramAdsStat.program_id'=>$id
				),
				'recursive'=>-1
		));
		$this->set('stat_program', $stat_program);
		CakeLog::write('stat_program', print_r($stat_program, 1));
		
		//conversations details
		$conversations = ClassRegistry::init('AppUserConversation')->find('all', array(
				'conditions'=> array(
					'AppUserConversation.program_id'=>$id
		 ),
		 'recursive'=>-1
		));
		$this->set('conversations', $conversations);

		// broadcast code for individual programs
        //ad compilation
		$ads = ClassRegistry::init('AdCampaign')->find('all', array(
				'conditions'=>array(
					'AdCampaign.json_url is not null'
				),
				'fields'=>array(
					'AdCampaign.id',
					'AdCampaign.name'
				),
				'recursive'=>-1
		));

		$advertisements = array();

		foreach($ads as $ad){
			$advertisements[$ad['AdCampaign']['id']] = $ad['AdCampaign']['name'];
		}
		 //method to broadcast the data
                if($this->request->is('post')){
                   if($id && $this->request->data['Program']['adCampaign_id']){
                   $ad = ClassRegistry::init('AdCampaign')->find('all', array(
                    'conditions'=>array(
                        'AdCampaign.id'=>$this->request->data['Program']['adCampaign_id']
                    ),
                    'recursive'=>-1
                ));
                $json_url = $ad[0]['AdCampaign']['json_url'];
                $appUsers = ClassRegistry::init('AppUser')->find('all', array(
                    'conditions'=>array(
                        'AppUser.current_program_id'=>$id
                    )
                ));
                $usersList = array();
                foreach($appUsers as $appUser){
                    $usersList[] = $appUser['AppUser']['id'];
                }
                $request_data = array(
                        'type'=>"ad",
                        'ad_campaign_id'=>$this->request->data['Program']['adCampaign_id'],
                        'ad_campaign_name'=>$advertisements[$this->request->data['Program']['adCampaign_id']],
                        'program_id'=>$id,
                        'program_name'=>$programs[$id],
                        'json_url' => $json_url

                );
                $request_json = json_encode($request_data);
                $gcmRegids = array();
                $gcmregidList = array();

                foreach($usersList as $user){ CakeLog::write('gcmregids', print_r($user, 1));
                $gcmRegids = ClassRegistry::init('Gcm')->find('all', array(
                        'conditions'=>array('Gcm.app_user_id'=>$user),
                        'fields' => 'Gcm.gcm_regid',
                        'recursive'=>-1
                    ));
                $gcmregidList[]=$gcmRegids[0][Gcm][gcm_regid];
                }

                CakeLog::write('gcmregids', print_r($gcmregidList, 1));

                $this->gcm_broadcast($gcmregidList, $request_json);
				$this->Session->setFlash(__('Broadcasted Ad to '.count($usersList).' Users'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_success');
            }else{
                if(!empty($this->request->data['ProgramGcm']['Message'])) {

                    $message = array(
                             "type" => "program",
                             "text" => $this->request->data['ProgramGcm']['Message'],
                             "program_id" => $id

                        );
                    $message = json_encode($message);
                    $this->programnotify($message);
                    CakeLog::write('vpro','message sent successfully');
                }
                        //CakeLog::write('vpro', 'message not sent');
                        //$this->Session->setFlash(__('Select the Ad and program'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_error');
            }
        }

        $this->set(compact('programs', 'advertisements'));
	
	}

		public function programnotify($message) {

            $regList=ClassRegistry::init('Gcm')->find('all', array('fields'=>'Gcm.gcm_regid'));
            $regids=array();
            foreach($regList as $rgids) {
                $regids[]=$rgids['Gcm']['gcm_regid'];
            }
            $fields = array(
				'registration_ids'  => $regids,
				'delay_while_idle' => false,
				'data'              => array( "message" => $message ),
			);
			$googlegcmkey = Configure::read('gcm.google_apikey');
	        $googlegcmurl = Configure::read('gcm.google_gcmurl');
			$headers = array(
					'Authorization: key='.$googlegcmkey,
					'Content-Type: application/json'
			);
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $googlegcmurl); 
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
			$result = curl_exec($ch);

			if ($result === FALSE) {
					die('Problem occurred: ' . curl_error($ch));
			}

			curl_close($ch);
			CakeLog::write('gcmlogs', print_r($fields, 1));
   }	


/**
 * add method
 *
 * @return void
 */
	public function add() {
		if ($this->request->is('post')) {
			$this->Program->create();
			if ($this->Program->save($this->request->data)) {
				$this->Session->setFlash(__('The program has been saved'));
				$this->redirect(array('action' => 'index'));
			} else {
				$this->Session->setFlash(__('The program could not be saved. Please, try again.'));
			}
		}
		$channels = $this->Program->Channel->find('list');
		$this->set(compact('channels'));
	}

/**
 * edit method
 *
 * @throws NotFoundException
 * @param string $id
 * @return void
 */
	public function edit($id = null) {
		$this->Program->id = $id;
		if (!$this->Program->exists()) {
			throw new NotFoundException(__('Invalid program'));
		}
		if ($this->request->is('post') || $this->request->is('put')) {
			if ($this->Program->save($this->request->data)) {
				$this->Session->setFlash(__('The program has been saved'));
				$this->redirect(array('action' => 'index'));
			} else {
				$this->Session->setFlash(__('The program could not be saved. Please, try again.'));
			}
		} else {
			$this->request->data = $this->Program->read(null, $id);
		}
		$channels = $this->Program->Channel->find('list');
		$this->set(compact('channels'));
	}

/**
 * delete method
 *
 * @throws MethodNotAllowedException
 * @throws NotFoundException
 * @param string $id
 * @return void
 */
	public function delete($id = null) {
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}
		$this->Program->id = $id;
		if (!$this->Program->exists()) {
			throw new NotFoundException(__('Invalid program'));
		}
		if ($this->Program->delete()) {
			$this->Session->setFlash(__('Program deleted'));
			$this->redirect(array('action' => 'index'));
		}
		$this->Session->setFlash(__('Program was not deleted'));
		$this->redirect(array('action' => 'index'));
	}

 /*
  * To checking ther user to program
  *
  *
  */

    public function checkin($app_user_id, $program_id){
		$apikey = $this->request->query['apikey'];
        $app_user_id = $this->request->query['app_user_id'];
		CakeLog::write('access', 'checkin');
		//nbr_app_returns
        $last_checkin_time = ClassRegistry::init('AppUserProgramLog')->find('all', array(
                'conditions'=>array(
                    'AppUserProgramLog.app_user_id'=>$app_user_id,
                    'AppUserProgramLog.program_id'=>$program_id,
                ),
                'fields'=>array(
                    'AppUserProgramLog.check_in_time',
                    'AppUserProgramLog.program_id',
                    'AppUserProgramLog.program_name',
                    'AppUserProgramLog.app_user_id',
                    'AppUserProgramLog.created'
                ),
                'recursive'=>-1,
                'order'=>array('AppUserProgramLog.created DESC'),
                'limit'=>1

        ));
        if(isset($last_checkin_time[0]['AppUserProgramLog']['check_in_time'])){
                $last_checkin = $last_checkin_time[0]['AppUserProgramLog']['check_in_time'];
        }
        else{
                $last_checkin = date('Y-m-d h:i:s');
        }
        $current_time = date('Y-m-d h:i:s');

        $diff = abs(strtotime($current_time) - strtotime($last_checkin));
        $years   = floor($diff / (365*60*60*24));
        $months  = floor(($diff - $years * 365*60*60*24) / (30*60*60*24));
        $days    = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24)/ (60*60*24));
        $hours   = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - $days*60*60*24)/ (60*60));
        $minuts  = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - $days*60*60*24 - $hours*60*60)/ 60);
        $seconds = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - $days*60*60*24 - $hours*60*60 - $minuts*60));

        CakeLog::write('nbr_app_returns', print_r($last_checkin_time, 1));
        CakeLog::write('nbr_app_returns', print_r($last_checkin, 1));
        CakeLog::write('nbr_app_returns', print_r($current_time, 1));
        CakeLog::write('nbr_app_returns', print_r($days, 1));
        if($days > 10){
                $query = "update app_user_gamification_profile set nbr_app_returns = nbr_app_returns + 1 where app_user_id=$app_user_id";
                CakeLog::write('nbr_app_returns', print_r($query, 1));
                ClassRegistry::init('AppUserGamificationProfile')->query($query);
        }

        $programDetails = $this->Program->findById($program_id);
	    $data = array();
        $data['AppUserProgramLog'] = array(
            'app_user_id'=>$app_user_id,
            'program_id'=>$program_id,
            'program_name'=>$programDetails['Program']['name'],
            'check_in_time'=>date('Y-m-d h:i:s')
        );

        if($res = ClassRegistry::init('AppUserProgramLog')->save($data)){

            $programDetails = $this->Program->find('all', array(
                'conditions'=>array(
                    'Program.id'=>$program_id
                ),
                'fields'=>array(
                    'Program.id',
                    'Program.nbr_views',
                    'Program.name'
                ),
                'recursive'=>-1
            ));
	    $data =array();
            $data['AppUser'] = array(
                'id'=>$app_user_id,
                'current_program_id'=>$program_id,
                'current_program_name'=>$programDetails[0]['Program']['name']
            );
	
            $returndata = ClassRegistry::init('AppUser')->saveAll($data);
            $friends = $this->Program->getFriedsList($app_user_id);
			CakeLog::write('notifyfriends', '@checkin- friendslist'); CakeLog::write('notifyfriends', print_r($friends, 1));
            $nbr_views = $programDetails[0]['Program']['nbr_views'] + 1;
	    
   	    $data = array();        
            $data['Program'] = array(
                'id'=>$program_id,
                'nbr_views'=>$nbr_views
            );

            if($program_id!=1){$this->notify_friends($friends); CakeLog::write('notifyfriends','@checkin - notify_friends method accessed');}
            if($this->Program->saveAll($data)){
                return $res['AppUserProgramLog']['id'];
            }
        }
    }

    /*
     *
     * To Check if user has come out of previous session
     *
     */
    public function checkout_oldprogram($app_user_id)
         {
             $user = ClassRegistry::init('AppUser')->find('first', array(
                 'conditions'=>array(
                     'AppUser.id'=>$app_user_id,
                     'AppUser.current_program_id > 0'
                 ),
                 'recursive'=>-1
             ));
           if($user) {  //There is a previous checkin without a corresponding checkout
                $cpgid = $user['AppUser']['current_program_id'];
				CakeLog::write('access', 'check_oldprogram');
                $sessions = ClassRegistry::init('AppUserProgramLog')->find('all', array(
                 'conditions'=>array(
                     'AppUserProgramLog.app_user_id'=>$app_user_id,
#                     'AppUserProgramLog.program_id'=>$cpgid,
                     'AppUserProgramLog.check_out_time is NULL'
                 ),
                 'recursive'=>-1
             ));

                foreach($sessions as $session)
                    { 
                       $data = array(
                    'AppUserProgramLog'=>array(
                        'id'=>$session['AppUserProgramLog']['id'],
                        'check_out_time'=>date('Y-m-d h:i:s')
                    ));
                $sid=$session['AppUserProgramLog']['id'];
                $now_time=date('Y-m-d h:i:s');
                $query = "update app_user_program_logs set check_out_time = '$now_time' where id = $sid";
                $this->Program->query($query);
               }   
                $query = 'update app_users set current_program_id = null, current_program_name = null where id = '.$app_user_id;
                $this->Program->query($query);
				CakeLog::write('access', 'exit - checkout_oldprogram');
		}
     }
    /*
     *
     * To Checkout the session
     *
     */

    public function api_checkout(){
		CakeLog::write('access','api_checkout_invoked successfully');
        if($this->request->data['session_id']){
            $session = ClassRegistry::init('AppUserProgramLog')->findById($this->request->data['session_id']);

            if($session){
                $data = array(
                    'AppUserProgramLog'=>array(
                        'id'=>$this->request->data['session_id'],
                        'check_out_time'=>date('Y-m-d h:i:s')
                    )
                );

                $query = 'update app_users set current_program_id = null, current_program_name = null where id = '.$session['AppUserProgramLog']['app_user_id'];
                $this->Program->query($query);

				$query = 'update programs set nbr_views = nbr_views - 0 where id = '.$session['AppUserProgramLog']['program_id'];
                $this->Program->query($query);

                if(ClassRegistry::init('AppUserProgramLog')->saveAll($data)){
                    $response = array(
                        'responseCode'=>'200',
                        'responseMessage'=>'Checked out successfully'
                    );
					CakeLog::write('access', 'api_checkout - hit -checked out successfully');
                    $this->callbackResponse = json_encode($response);
                    return;
                }else{
                    $response = array(
                        'responseCode'=>'300',
                        'responseMessage'=>'Unable to checkout, please try again'
                    );
                    $this->callbackResponse = json_encode($response);
                    return;
                }
            }else{
                $response = array(
                    'responseCode'=>'300',
                    'responseMessage'=>'Unknown Session, please pass valid session'
                );

                $this->callbackResponse = json_encode($response);
                return;
            }

        }else{
            $response = array(
                'responseCode'=>'404',
                'responseMessage'=>'Missing Parameters Or unknown user'
            );

            $this->callbackResponse = json_encode($response);
            return;
        }
    }

    public function reload(){
        $this->autoRender = false;
        $this->layout = false;
        $this->Program->loadPrograms();
        echo 'Done';
    }
	
	public function reloadtest(){
        $this->autoRender = false;
        $this->layout = false;
        $this->Program->loadPrograms7();
        echo 'Reload successfully Done';
    }

    /**
     *
     * Lounge API to get the lounge data
     */
     public function api_lounge(){

	$apikey = $this->request->query['apikey'];
	$app_user_id = $this->request->query['app_user_id'];

	if($this->request->query['app_user_id']){
             $channels = $this->Program->Channel->find('list');
	     $app_user_id = $this->request->query['app_user_id'];
	     if( $this->request->query['type'] == 'refresh' ){
		$session_id = 0;
	     }else{
		$session_id = $this->checkin($app_user_id, 1);
	     }

             /* Lounge Programs that has maximum hits and Conversations */
             $programsList = $this->Program->find('all', array(
                 'order'=>'Program.nbr_views DESC, Program.nbr_conversations DESC',
                 'recursive'=>-1,
                 'limit'=>3,
                 'conditions'=>array(
                     'Program.id != 1',
                     'Program.end_time > '=>date('Y-m-d H:i:s'),
                     'Program.start_time < '=>date('Y-m-d H:i:s', strtotime('+3 hours')),
                     'Program.nbr_views > 0'
                 ),
                 'fields'=>array(
                     'Program.id',
                     'Program.name',
                     'Program.thumbnail',
                     'Program.start_time',
                     'Program.end_time',
                     'Program.nbr_views',
                     'Program.nbr_conversations',
                     'Program.channel_id'
                 )
             ));

             $friendsPanelPrograms = $programsList;

             $loungePrograms = array();
             foreach($programsList as $prg){
                 $loungePrograms[] = array(
                     'id'=>$prg['Program']['id'],
                     'name'=>$prg["Program"]['name'],
                     'thumbnail'=>$prg['Program']['thumbnail'],
                     'nbr_views'=>$prg['Program']['nbr_views'],
                     'nbr_conversations'=>$prg['Program']['nbr_conversations'],
                     'channel'=>$channels[$prg['Program']['channel_id']],
                     'start_time'=>$prg['Program']['start_time'],
                     'end_time'=>$prg['Program']['end_time']
                 );
             }

             //Upcoming programs that are like the favorite programs
             $favoriteProgramsList = ClassRegistry::init('FavoriteProgram')->find('all', array(
                 'conditions'=>array(
                     'FavoriteProgram.app_user_id' => $app_user_id
                 ),
                 'fields'=>array(
                     'FavoriteProgram.program_name'
                 ),
                 'limit'=>5,
                 'order'=>array(
                     'FavoriteProgram.id'
                 ),
                 'recursive'=>-1
             ));

             if($favoriteProgramsList){
                 $conditions = array();

                 foreach($favoriteProgramsList as $fav){
                    $conditions['OR'][] = array('Program.name LIKE' => '%' . $fav['FavoriteProgram']['program_name'] . '%');
                 }

                 $programsLikeFavorite = $this->Program->find('all', array(
                     'conditions'=>array(                   
                         'Program.end_time > '=>date('Y-m-d H:i:s'),
                         'Program.start_time < '=>date('Y-m-d H:i:s', strtotime('+3 hours')),
                         $conditions
                     ),
                     'recursive'=>-1,
                     'limit'=>5,
                     'order'=>array(
                         'Program.start_time ASC'
                     ),
                     'fields'=>array(
                         'Program.id',
                         'Program.name',
                         'Program.thumbnail',
                         'Program.start_time',
                         'Program.end_time',
                         'Program.nbr_views',
                         'Program.nbr_conversations',
                         'Program.channel_id'
                     )
                 ));

                 if($programsLikeFavorite){
                     foreach($programsLikeFavorite as $prg){
                         $loungePrograms[] = array(
                             'id'=>$prg['Program']['id'],
                             'name'=>$prg["Program"]['name'],
                             'thumbnail'=>$prg['Program']['thumbnail'],
                             'nbr_views'=>$prg['Program']['nbr_views'],
                             'nbr_conversations'=>$prg['Program']['nbr_conversations'],
                             'channel'=>$channels[$prg['Program']['channel_id']],
                             'start_time'=>$prg['Program']['start_time'],
                             'end_time'=>$prg['Program']['end_time']
                         );
                     }
                 }
             }

             //Programs that are like watched yesterday
             $yesterdayPrograms = ClassRegistry::init('AppUserProgramLog')->find('all', array(
                 'conditions'=>array(
                     'date(AppUserProgramLog.check_in_time)' => date('Y-m-d', strtotime('-1 day')),
                     'program_id != 1'
                 )
             ));

             if($yesterdayPrograms){
                 $conditions = array();

                 foreach($yesterdayPrograms as $fav){
                     $conditions['OR'][] = array('Program.name LIKE' => '%' . $fav['AppUserProgramLog']['program_name'] . '%');
                 }

                 $programLikeYesterdays = $this->Program->find('all', array(
                     'conditions'=>array(                         
                         'Program.end_time > '=>date('Y-m-d H:i:s'),
                         'Program.start_time < '=>date('Y-m-d H:i:s', strtotime('+3 hours')),
                         $conditions
                     ),
                     'recursive'=>-1,
                     'limit'=>5,
                     'group'=>array(
                         'Program.channel_id'
                     ),
                     'order'=>array(
                         'Program.start_time ASC'
                     ),
                     'fields'=>array(
                         'DISTINCT Program.channel_id',
                         'Program.id',
                         'Program.name',
                         'Program.thumbnail',
                         'Program.start_time',
                         'Program.end_time',
                         'Program.nbr_views',
                         'Program.nbr_conversations',
                     )
                 ));

                 if($programLikeYesterdays){
                     foreach($programLikeYesterdays as $prg){
                         $loungePrograms[] = array(
                             'id'=>$prg['Program']['id'],
                             'name'=>$prg["Program"]['name'],
                             'thumbnail'=>$prg['Program']['thumbnail'],
                             'nbr_views'=>$prg['Program']['nbr_views'],
                             'nbr_conversations'=>$prg['Program']['nbr_conversations'],
                             'channel'=>$channels[$prg['Program']['channel_id']],
                             'start_time'=>$prg['Program']['start_time'],
                             'end_time'=>$prg['Program']['end_time']
                         );
                     }
                 }
             }

             //Rest of the Programs from rest of gender_channel_mapping
             $user = ClassRegistry::init('AppUser')->find('all', array(
                 'conditions'=>array(
                     'AppUser.id'=>$app_user_id
                 ),
                 'recursive'=>-1
             ));

             $gender = 0;
             switch($user[0]['AppUser']['gender']){
                 case 'male':
                 $gender = 0;
                 break;

                 case 'female':
                     $gender = 1;
                     break;

                 default:
                     $gender = 0;
                     break;
             }

             $channelsList = ClassRegistry::init('GenderChannelMapping')->find('all', array(
                 'conditions'=>array(
                     'GenderChannelMapping.gender'=>$gender
                 )
             ));

             $channelsId = array();
             foreach($channelsList as $channel){
                 $channelsId[] = $channel['GenderChannelMapping']['channel_id'];
             }

             $restofprograms = $this->Program->find('all', array(
                 'conditions'=>array(                    
                     'Program.end_time > '=>date('Y-m-d H:i:s'),
                     'Program.start_time < '=>date('Y-m-d H:i:s', strtotime('+3 hours')),
                     'channel_id in ( '.implode(',', $channelsId).' ) '
                 ),
                 'recursive'=>-1,
                 'limit'=>30,
                 'order'=>array(
                     'Program.start_time ASC'
                 ),
                 'group'=>array(
                     'Program.channel_id'
                 ),
                 'fields'=>array(
                     'DISTINCT Program.channel_id',
                     'Program.id',
                     'Program.name',
                     'Program.thumbnail',
                     'Program.start_time',
                     'Program.end_time',
                     'Program.nbr_views',
                     'Program.nbr_conversations'
                 )
             ));

             if($restofprograms){
                 foreach($restofprograms as $prg){
                     $loungePrograms[] = array(
                         'id'=>$prg['Program']['id'],
                         'name'=>$prg["Program"]['name'],
                         'thumbnail'=>$prg['Program']['thumbnail'],
                         'nbr_views'=>$prg['Program']['nbr_views'],
                         'nbr_conversations'=>$prg['Program']['nbr_conversations'],
                         'channel'=>$channels[$prg['Program']['channel_id']],
                         'start_time'=>$prg['Program']['start_time'],
                         'end_time'=>$prg['Program']['end_time']
                     );
                 }
             }

             $loungePrograms = array_map("unserialize", array_unique(array_map("serialize", $loungePrograms)));

             $loungePrograms = array_slice($loungePrograms, 0, 30);
             $response = array(
                 'responseCode'=>200,
                 'responseMessaage'=>'Lounge Programs and Conversations',
                 'loungePrograms'=>$loungePrograms,
                 'loungeConversations'=>ClassRegistry::init('AppUserConversation')->getConversations()
             );

            $friendsDefaultPrograms = $loungePrograms;

             /* Friends Related Programs and Conversations*/
             $loungeProgramsId = array();
             $loungeprogramsList = array();
             $loungePrograms = array();

             if($this->Program->getFriedsList($app_user_id)){

                 $programsList = ClassRegistry::init('AppUserProgramLog')->find('all', array(
                     'limit'=>30,
                     'recursive'=>-1,
                     'fields'=>array(
                         'AppUserProgramLog.program_id DISTINCT'
                     ),
                     'conditions'=>array(
                         'AppUserProgramLog.app_user_id in ( '.implode(',',$this->Program->getFriedsList($app_user_id)).' ) '
                     )
                 ));

                 foreach($programsList as $prg){
                     $loungeProgramsId[] = $prg['AppUserProgramLog']['program_id'];
                 }

                 if(!empty($loungeProgramsId)){
                     $loungeprogramsList = $this->Program->find('all', array(
                         'recursive'=>-1,
                         'fields'=>array(
                             'Program.id',
                             'Program.name',
                             'Program.description',
                             'Program.thumbnail',
                             'Program.start_time',
                             'Program.end_time',
                             'Program.nbr_views',
                             'Program.nbr_conversations',
                             'Program.channel_id'
                         ),
                         'conditions'=>array(
                             'Program.id in ( '.implode(',',$loungeProgramsId).')',
                             'Program.end_time > '=>date('Y-m-d H:i:s'),
                             'Program.start_time < '=>date('Y-m-d H:i:s', strtotime('+3 hours')),
                             'Program.id != 1'
                         )
                     ));

                     if(!empty($loungeprogramsList)){
                         foreach($loungeprogramsList as $prg){
                             $loungePrograms[] = array(
                                 'id'=>$prg['Program']['id'],
                                 'name'=>$prg["Program"]['name'],
                                 'thumbnail'=>$prg['Program']['thumbnail'],
                                 'nbr_views'=>$prg['Program']['nbr_views'],
                                 'nbr_conversations'=>$prg['Program']['nbr_conversations'],
                                 'channel'=>$channels[$prg['Program']['channel_id']],
                                 'start_time'=>$prg['Program']['start_time'],
                                 'end_time'=>$prg['Program']['end_time']
                             );
                         }
                     }else{
                         $loungePrograms = $friendsDefaultPrograms;
                     }
                 }else{
                     $loungePrograms = $friendsDefaultPrograms;
                 }
             }else{
                 $loungePrograms = $friendsDefaultPrograms;
             }

             $response['loungeFriendsPrograms'] = $loungePrograms;
             $response['loungeFriendsConversations'] = ClassRegistry::init('AppUserConversation')->getFriendsConversations($app_user_id);
             $response['sessionid'] = $session_id;
             $response['friendspanel'] = $this->friendspanel($app_user_id, $friendsPanelPrograms, 1);
             $response['friendsfootprint'] = $this->friendsfootprint($app_user_id);
             $this->callbackResponse = json_encode($response);
         }
     }

    /*
     *
     * API Method to get the list of all program with channels details
     *
     * */
    public function api_getall(){
	$this->Program->fields = array(
            'Program.id'
        );

        $allPrograms = $this->Program->Channel->find('all', array(
            'fields'=>array(
                'Channel.id',
                'Channel.name',
                'Channel.genre',
                'Channel.language',
                'Channel.description',
                'Channel.logo_url'
            ),
            'conditions'=>array(
                'Channel.id != 1'
            )
        ));

        debug($allPrograms);
        $programs = array();
        $channelList = array();

        foreach( $allPrograms as $program ){

            $tmp = array();
            $channelList[] = $tmp['channel'] = array(
                'id'=>$program['Channel']['id'],
                'name'=>$program['Channel']['name'],
                'genre'=>$program['Channel']['genre'],
                'language'=>$program['Channel']['language'],
                'logo_url'=>$program['Channel']['logo_url']
            );

            foreach($program['Program'] as $prg){
                $tmp['programs'][] = array(
                    'id'=>$prg['id'],
                    'name'=>$prg['name'],
                    'start_time'=>$prg['start_time'],
                    'end_time'=>$prg['end_time'],
                    'channel_id'=>$prg['channel_id']
                );
            }

            $programs[] = $tmp;
        }

        $response = array(
            'responseCode'=>'200',
            'responseMessage'=>'List of Programs for all Channels',
            'channelList'=>$channelList,
            'channels'=>$programs
        );

        $this->callbackResponse = json_encode($response);
    }

    /*
     *
     * API to get the Program Details
     */
    public function api_get(){
        // get the current time from server and update for every get request
		CakeLog::write('vpro', print_r($this->request->query, 1));
        $refresh_time = date('Y-m-d h:i:s');
        $app_user_id = $this->request->query['app_user_id'];
        $current_program_id = $this->request->query['program_id'];
        $query = "update app_users set refreshtimestamp='$refresh_time' where id='$app_user_id'";
        ClassRegistry::init('AppUser')->query($query);
		CakeLog::write('vpro', 'api_get'); CakeLog::write('vpro', print_r($this->request->query, 1));
		if($this->request->query['program_id'] && $this->request->query['app_user_id']){
	    
	    if($this->request->query['type'] == 'refresh' ){
	    	$session_id = 0;
 	    }else{
			//$this->checkout_oldprogram($this->request->query['app_user_id']);
			$session_id = $this->checkin($this->request->query['app_user_id'], $this->request->query['program_id']);
			CakeLog::write('access', 'api_get - if type is not refresh');
	    }

		$program = $this->Program->find('all',array(
			'conditions'=>array(
				'Program.id'=>$this->request->query['program_id']
			),
			'recursive'=>0
		));
            $conversation = ClassRegistry::init('AppUserConversation')->getConversations($this->request->query['program_id']);
            $friendsConversation = ClassRegistry::init('AppUserConversation')->getFriendsConversations($this->request->query['app_user_id'], $this->request->query['program_id']);
            $favoritePrograms = ClassRegistry::init('FavoriteProgram')->find('all', array(
                'conditions'=>array(
                    'FavoriteProgram.app_user_id' => $this->request->query['app_user_id']
                )
            ));

            $favProgram = array();
            foreach($favoritePrograms as $favoriteProgram){
                $favProgram[] = $favoriteProgram['FavoriteProgram']['program_id'];
            }

            if(in_array($program[0]['Program']['id'], $favProgram)){
                $program[0]['Program']['is_favorite'] = 1;
            }else{
                $program[0]['Program']['is_favorite'] = 0;
            }

            /* Lounge Programs that has maximum hits and Conversations */
            $programsList = $this->Program->find('all', array(
                'order'=>'Program.nbr_views DESC, Program.nbr_conversations DESC',
                'recursive'=>-1,
                'limit'=>3,
                'conditions'=>array(
                    'Program.id != 1',
                    'Program.broadcasting_date' => date('Y-m-d'),
                    'time(Program.end_time) > '=>date('H:i:s'),
                    'time(Program.start_time) < '=>date('H:i:s', strtotime('+3 hours'))
                ),
                'fields'=>array(
                    'Program.id',
                    'Program.name',
                    'Program.thumbnail',
                    'Program.start_time',
                    'Program.end_time',
                    'Program.nbr_views',
                    'Program.nbr_conversations',
                    'Program.channel_id'
                )
            ));

		/* Current Program of the user and its details */
	     $Currentprogram = $this->Program->find('all', array(
                'order'=>'Program.nbr_views DESC, Program.nbr_conversations DESC',
                'recursive'=>-1,
                'conditions'=>array(
                    'Program.id'=>$this->request->query['program_id']
                ),
                'fields'=>array(
                    'Program.id',
                    'Program.name',
                    'Program.thumbnail',
                    'Program.start_time',
                    'Program.end_time',
                    'Program.nbr_views',
                    'Program.nbr_conversations',
                    'Program.channel_id'
                )
            ));

        $programsList = array_merge($Currentprogram, $programsList);
		//CakeLog::Write('vpro', print_r($programsList, 1));
          	
            $response = array(
                'responseCode'=>200,
                'responseMessage'=>'Program Details',
                'program'=>$program[0]['Program'],
                'channel'=>$program[0]['Channel'],
                'conversations'=>$conversation,
                'friendsconversations'=>$friendsConversation,
                'sessionid'=>$session_id,
                'friendspanel'=>$this->friendspanel($this->request->query['app_user_id'], $programsList, $this->request->query['program_id'])
            );
            $this->callbackResponse = json_encode($response);

        }else{
            $response = array(
                'responseCode'=>'400',
                'responseMessage'=>'Missing Parameters',
            );

            $this->callbackResponse = json_encode($response);
        }
    }

    public function friendspanel($app_user_id, $programsList, $program_id){

        $friendsList = $this->Program->getFriedsList($app_user_id);
        $response = array();

        if($friendsList){
            if($program_id != 1){
                $friendsWatchinCurrProgram = ClassRegistry::init('AppUser')->find('all', array(
                    'recursive'=>-1,
                    'fields'=>array(
                        'AppUser.id',
                        'AppUser.name',
                        'AppUser.fb_user_id',
                    ),
		            'limit'=>10,
                    'conditions'=>array(
                        'AppUser.current_program_id'=>$program_id,
                        'AppUser.id in ('.implode(',',$friendsList).')'
                    )
                ));

                foreach($friendsWatchinCurrProgram as $index=>$frnd){
                    $response['friendsinthisprogram'][$index] = array(
                        'id'=>$frnd['AppUser']['id'],
                        'name'=>$frnd['AppUser']['name'],
                        'fb_user_id'=>$frnd['AppUser']['fb_user_id']
                    );
                }
            }

            $friendsWatching = ClassRegistry::init('AppUser')->find('all', array(
                'recursive'=>-1,
                'fields'=>array(
                    'AppUser.id',
                    'AppUser.name',
                    'AppUser.fb_user_id',
                ),
		        'limit'=>10,
                'conditions'=>array(
                    'AppUser.current_program_id'=>1,
                    'AppUser.id in ('.implode(',',$friendsList).')'
                )
            ));

            if(!empty($friendsWatching)){
                $response = array();
            }

            foreach($friendsWatching as $index=>$frnd){
                $response['friendswatchinginlounge'][$index] = array(
                    'id'=>$frnd['AppUser']['id'],
                    'name'=>$frnd['AppUser']['name'],
                    'fb_user_id'=>$frnd['AppUser']['fb_user_id']
                );
            }

            $mostWatchedAmongFriend = ClassRegistry::init('AppUser')->find('all', array(
                'recursive'=>-1,
                'fields'=>array(
                    'AppUser.current_program_id',
                ),
                'group'=>'AppUser.current_program_id',
                'order'=>'count(AppUser.current_program_id) DESC',
                'limit'=>3,
                'conditions'=>array(
                    'AppUser.id in ('.implode(',',$friendsList).')',
                    'AppUser.current_program_id != 1'
                )
            ));

            $progrmIds = array();

            foreach($mostWatchedAmongFriend as $prg){
                $progrmIds[] = $prg['AppUser']['current_program_id'];
            }

            if(!empty($progrmIds)){

                $programsList = $this->Program->find('all', array(
                    //'order'=>'Program.nbr_views DESC, Program.nbr_conversations DESC',
                    'recursive'=>-1,
                    'limit'=>3,
                    'conditions'=>array(
                        'Program.id in ('.implode(',',$progrmIds).')',
						'Program.broadcasting_date' => date('Y-m-d')
                    ),
                    'fields'=>array(
                        'Program.id',
                        'Program.name',
                        'Program.thumbnail',
                        'Program.start_time',
                        'Program.end_time',
                        'Program.nbr_views',
                        'Program.nbr_conversations',
                        'Program.channel_id'
                    )
                ));

                foreach($programsList as $key=>$prg){
                    $response['programs'][$key]['program'] = array(
                        'id'=>$prg['Program']['id'],
                        'name'=>$prg['Program']['name'],
                        'thumbnail'=>$prg['Program']['thumbnail'],
                        'nbr_views'=>$prg['Program']['nbr_views'],
                        'nbr_conversations'=>$prg['Program']['nbr_conversations']
                    );

                    if($friendsList){
                        $friendsWatching = ClassRegistry::init('AppUser')->find('all', array(
                            'recursive'=>-1,
                            'fields'=>array(
                                'AppUser.id',
                                'AppUser.name',
                                'AppUser.fb_user_id',
                            ),
                            'limit'=>10,
                            'conditions'=>array(
                                'AppUser.current_program_id'=>$prg['Program']['id'],
                                'AppUser.id in ('.implode(',',$friendsList).')'
                            )
                        ));

                        foreach($friendsWatching as $index=>$frnd){
                            $response['programs'][$key]['friendswatching'][$index] = array(
                                'id'=>$frnd['AppUser']['id'],
                                'name'=>$frnd['AppUser']['name'],
                                'fb_user_id'=>$frnd['AppUser']['fb_user_id']
                            );
                        }
                    }
                }
            }
        }

        if(empty($response)){
            $response = new StdClass();
        }

        return $response;
    }

public function friendsfootprint($app_user_id){

        $friendsList = $this->Program->getFriedsList($app_user_id);
        $response = array();

        if($friendsList){
            $mostWatchedAmongFriend = ClassRegistry::init('AppUserProgramLog')->find('all', array(
                'recursive'=>-1,
                'fields'=>array(
                    'AppUserProgramLog.program_id',
                ),
                'group'=>'AppUserProgramLog.program_id',
                'order'=>'AppUserProgramLog.program_id DESC',
                'limit'=>10,
                'conditions'=>array(
                    'AppUserProgramLog.app_user_id in ('.implode(',',$friendsList).')',
                    'AppUserProgramLog.program_id != 1',
                    'AppUserProgramLog.created >' => date('Y-m-d H:i:s',strtotime('-10 days')),
                    'AppUserProgramLog.created <' => date('Y-m-d H:i:s',strtotime('-3 hours'))
                )
            ));

            $progrmIds = array();

            foreach($mostWatchedAmongFriend as $prg){
                $progrmIds[] = $prg['AppUserProgramLog']['program_id'];
            }

            if(!empty($progrmIds)){

                $programsList = $this->Program->find('all', array(
                    //'order'=>'Program.nbr_views DESC, Program.nbr_conversations DESC',
                    'recursive'=>-1,
                    'limit'=>10,
                    'conditions'=>array(
                        'Program.id in ('.implode(',',$progrmIds).')'
                    ),
                    'fields'=>array(
                        'Program.id',
                        'Program.name',
                        'Program.thumbnail',
                        'Program.start_time',
                        'Program.end_time',
                        'Program.nbr_views',
                        'Program.nbr_conversations',
                        'Program.channel_id'
                    )
                ));

                foreach($programsList as $key=>$prg){
                    $response['programs'][$key]['program'] = array(
                        'id'=>$prg['Program']['id'],
                        'name'=>$prg['Program']['name'],
                        'thumbnail'=>$prg['Program']['thumbnail'],
                        'nbr_views'=>$prg['Program']['nbr_views'],
                        'nbr_conversations'=>$prg['Program']['nbr_conversations']
                    );

                    if($friendsList){
                        $friendsWatching = ClassRegistry::init('AppUserProgramLog')->find('all', array(
                            'recursive'=>-1,
                            'fields'=>array(
                                'DISTINCT AppUserProgramLog.app_user_id',
                            ),
                            'limit'=>10,
                            'conditions'=>array(
                                'AppUserProgramLog.program_id'=>$prg['Program']['id'],
                                'AppUserProgramLog.app_user_id in ('.implode(',',$friendsList).')'
                            )
                        ));
                          foreach($friendsWatching as $index=>$frnd){
                          $fid=ClassRegistry::init('AppUser')->find('first',
                                             array('conditions'=> array('AppUser.id' => $frnd['AppUserProgramLog']['app_user_id'])
                                             ,'fields'=> array('AppUser.name',
                                                               'AppUser.fb_user_id'),'recursive'=>-1));
                            $response['programs'][$key]['friendswatching'][$index] = array(
                                'id'=>$frnd['AppUserProgramLog']['app_user_id'],
                                'name'=>$fid['AppUser']['name'],
                                'fb_user_id'=>$fid['AppUser']['fb_user_id']
                            );
                        }
                    }
                }
            }
        }

        if(empty($response)){
            $response = new StdClass();
        }

        return $response;
    }


    public function api_favorite(){

        if($this->request->data['program_id'] && $this->request->data['app_user_id']){
            $programDetail = $this->Program->findById($this->request->data['program_id']);

            $data = array();
            $data['FavoriteProgram']['app_user_id'] = $this->request->data['app_user_id'];
            $data['FavoriteProgram']['program_id'] = $this->request->data['program_id'];
            $data['FavoriteProgram']['program_name'] = $programDetail['Program']['name'];
            $data['FavoriteProgram']['status'] = 1;

            if(!ClassRegistry::init('FavoriteProgram')->find('all', array(
                'conditions'=>array(
                    'FavoriteProgram.app_user_id'=>$this->request->data['app_user_id'],
                    'FavoriteProgram.program_id'=>$this->request->data['program_id']
                ),
                'recursive'=>-1
            ))){
                if(ClassRegistry::init('FavoriteProgram')->save($data)){
                    $response = array(
                        'responseCode'=>200,
                        'responseMessage'=>'Successfully Marked Favorite',
                        'status'=>1
                    );

                    $this->callbackResponse = json_encode($response);
                }else{
                    $response = array(
                        'responseCode'=>300,
                        'responseMessage'=>'Failed to mark Favorite',
                        'status'=>0
                    );

                    $this->callbackResponse = json_encode($response);
                }
            }else{
                $response = array(
                    'responseCode'=>200,
                    'responseMessage'=>'Allready Marked Favorite',
                    'status'=>0
                );

                $this->callbackResponse = json_encode($response);
            }

        }else{
            $response = array(
                'responseCode'=>'400',
                'responseMessage'=>'Missing Parameters',
            );

            $this->callbackResponse = json_encode($response);
        }
    }

// broadcast ads for kbc starts
	public function broadcastkbc(){
        $ads = ClassRegistry::init('AdCampaign')->find('all', array(
            'conditions'=>array(
                'AdCampaign.json_url is not null'
            ),
            'fields'=>array(
                'AdCampaign.id',
                'AdCampaign.name'
            ),
            'recursive'=>-1
        ));
		
		$programid = '2';
		$programname = 'Kaun Banega Crorepati 2013';
		$kbcstart_time='2013-08-26 18:30:00';
		$kbcchannel_id='32';

        $advertisements = array();

        foreach($ads as $ad){
            $advertisements[$ad['AdCampaign']['id']] = $ad['AdCampaign']['name'];
        }

        if($this->request->is('post')){
            if($this->request->data['Program']['adCampaign_id']){
                $ad = ClassRegistry::init('AdCampaign')->find('all', array(
                    'conditions'=>array(
                        'AdCampaign.id'=>$this->request->data['Program']['adCampaign_id']
                    ),
                    'recursive'=>-1
                ));

                $xmlData = $ad[0]['AdCampaign']['ad_xml'];

                $appUsers = ClassRegistry::init('AppUser')->find('all', array(
                    'conditions'=>array(
                        'AppUser.current_program_id'=>$programid
                    )
                ));

                $usersList = array();
                foreach($appUsers as $appUser){
                    $usersList[] = $appUser['AppUser']['id'];
                }

                $xml=preg_replace("/\r\n|\r|\n|\t/",'',$xmlData);

                $request_json = array(
                    "request_type"=>array(
                        "connection_type"=>"Broadcast",
                        "Broadcast"=>array(
                            'secret_key'=>"4b1f85260a78265f54714d11e051aba2765fbfbf727e01145de4838093a272a2",
                            'app_user_ids'=>$usersList,
                            'ad_campaign_id'=>$this->request->data['Program']['adCampaign_id'],
                            'ad_campaign_name'=>$advertisements[$this->request->data['Program']['adCampaign_id']],
                            'program_id'=>$programid,
                            'program_name'=>$programname,
                            'xml_data'=>$xml
                        )
                    )
                );

                $request_data = json_encode($request_json);

                $fp = stream_socket_client("tcp://www.teletango.com:8890",$errno,$errstr,30);
                $resp = fwrite($fp,$request_data);
                fclose($fp);

                $this->Session->setFlash(__('Broadcasted Ad to '.count($usersList).' Users'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_success');
            }else{
                $this->Session->setFlash(__('Select the Ad'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_error');
            }
        }

        $this->set(compact('programs', 'advertisements'));
    }
	
/*
 * Broadcast method for view
 * @ Load all the programs for today date with channels 
 * @ Load all the ads for the programs
 */
    public function broadcast(){
        $programsList = $this->Program->find('all', array(
            'conditions'=>array(
               'Program.broadcasting_date'=>date('Y-m-d')
            ),
            'recursive'=>-1,
            'fields'=>array(
                'Program.id',
                'Program.name',
                'Program.start_time',
                'Program.channel_id'
            )
        ));
        $channels = $this->Program->Channel->find('list');
        $programs = array();
        foreach($programsList as $prg){
            $programs[$prg['Program']['id']] = $prg['Program']['name'].' -'.$prg['Program']['id'].' ( '.$channels[$prg['Program']['channel_id']].' '.date('H:i:s', strtotime($prg['Program']['start_time'])).' ) ';
        }

        $ads = ClassRegistry::init('AdCampaign')->find('all', array(
            'conditions'=>array(
                'AdCampaign.json_url is not null'
            ),
            'fields'=>array(
                'AdCampaign.id',
                'AdCampaign.name'
            ),
            'recursive'=>-1
        ));

        $advertisements = array();

        foreach($ads as $ad){
            $advertisements[$ad['AdCampaign']['id']] = $ad['AdCampaign']['name'];
        }
        
		if($this->request->is('post')){ CakeLog::write('access', print_r($this->request->data, 1));
           if($this->request->data['Program']['program_id'] && $this->request->data['Program']['adCampaign_id']){
                $ad = ClassRegistry::init('AdCampaign')->find('all', array(
                    'conditions'=>array(
                        'AdCampaign.id'=>$this->request->data['Program']['adCampaign_id']
                    ),
                    'recursive'=>-1
                ));
                $json_url = $ad[0]['AdCampaign']['json_url'];
                $appUsers = ClassRegistry::init('AppUser')->find('all', array(
                    'conditions'=>array(
                        'AppUser.current_program_id'=>$this->request->data['Program']['program_id']
                    )
                ));
				//$usersList = array();
                //foreach($appUsers as $appUser){
                //    $usersList[] = $appUser['AppUser']['id'];
                //}
				//request_data for android devices
				$request_data = array(
					'type'=>"ad",
					'ad_campaign_id'=>$this->request->data['Program']['adCampaign_id'],
					'ad_campaign_name'=>$advertisements[$this->request->data['Program']['adCampaign_id']],
					'program_id'=>$this->request->data['Program']['program_id'],
					'program_name'=>$programs[$this->request->data['Program']['program_id']],
					'json_url' => $json_url
				);
                $request_json = json_encode($request_data);
				//request_data for iphone devices
				$request_data_ios = array(
					'type'=>"ad",
					'ad_campaign_id'=>$this->request->data['Program']['adCampaign_id'],
					'ad_campaign_name'=>$advertisements[$this->request->data['Program']['adCampaign_id']],
					'program_id'=>$this->request->data['Program']['program_id'],
					'program_name'=>$programs[$this->request->data['Program']['program_id']],
				);
				$ios_request_json = json_encode($request_data_ios);
				
				//User Operations
                $appUsers = ClassRegistry::init('AppUser')->find('all', array(
                    'conditions'=>array(
                        'AppUser.current_program_id'=>$this->request->data['Program']['program_id']
                    )
                )); 
				//CakeLog::write('access','ad json'); CakeLog::write('access', print_r($ios_request_json, 1));
                $usersList = array();
                foreach($appUsers as $appUser){
                    $usersList[] = $appUser['AppUser']['id'];
                }
		//common method to access the notification framework	
		$this->notification($usersList, $request_json, $ios_request_json);	
                
		$this->Session->setFlash(__('Broadcasted Ad to '.count($usersList).' Users'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_success');
            }else{
                $this->Session->setFlash(__('Select the Ad and program'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_error');
            }
        }

        $this->set(compact('programs', 'advertisements'));
    }

/*
 * common notification framework
 * @notification sent to android and ios devices based on the protocol
 *
 */
	public function notification($usersList, $android_json, $ios_json){
		foreach($usersList as $user){
			$regidList = ClassRegistry::init('Gcm')->find('all', array(
				'conditions'=>array('Gcm.app_user_id'=>$user),
				'fields'=>array('Gcm.gcm_regid','Gcm.protocol'),
				'recursive'=>-1
			));
			CakeLog::write('vpro', print_r($regidList[0]['Gcm']['gcm_regid'], 1));	
			CakeLog::write('vpro', print_r($regidList[0]['Gcm']['protocol'], 1));	
			if($regidList[0]['Gcm']['protocol']=="IOS"){
				//call ios notification method
				$this->ios_notification($regidList[0]['Gcm']['gcm_regid'], $ios_json);			
			}	
			elseif($regidList[0]['Gcm']['protocol']=="android"){
				//call gcm notification method
				$this->gcm_broadcast($regidList[0]['Gcm']['gcm_regid'], $android_json);			
			}
		}

	}


/*
 * iphone 
 * notification
 *
 */
	public function ios_notification($regid, $message) {
		$decodedm = json_decode($message, true);
		$gcmtest = $regid; #CakeLog::write('vpro', print_r($regid, 1));
                $passphrase = 'gvc3YDGXFu';
                $ctx = stream_context_create();
                stream_context_set_option($ctx, 'ssl', 'local_cert', '/var/www/ApptarixTV/app/webroot/key/teletango.pem');
                stream_context_set_option($ctx, 'ssl', 'passphrase', $passphrase);
                $fp = stream_socket_client(
                        'ssl://gateway.sandbox.push.apple.com:2195', $err,
                        $errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
                if (!$fp)
                        exit("Failed to connect: $err $errstr" . PHP_EOL);
                $body['aps'] = array(
                        'alert' => 'Teletango Ad',
                        'sound' => 'default'
                        );
				$body['content']=$message;
                $payload = json_encode($body);
                $msg = chr(0) . pack('n', 32) . pack('H*', $regid) . pack('n', strlen($payload)) . $payload;
                $result = fwrite($fp, $msg, strlen($msg));
                if (!$result)
                        echo 'Message not delivered' . PHP_EOL;
                else
                        echo 'Message successfully delivered' . PHP_EOL;
                fclose($fp);
	}

/*
 * android 
 * notification
 *
 */
	public function gcm_broadcast($regids, $message){
		CakeLog::write('vpro', 'gcm method accessed'); CakeLog::write('vpro', print_r($regids, 1)); CakeLog::write('vpro', print_r($message, 1));	
             	$googlegcmkey = Configure::read('gcm.google_apikey');
              	$googlegcmurl = Configure::read('gcm.google_gcmurl');
		$fields = array(
			'registration_ids'  => array($regids),
			'delay_while_idle' => false,
			'data'              => array( "message" => $message ),
		); CakeLog::write('vpro', print_r($fields, 1));
		$headers = array(
			'Authorization: key=' . $googlegcmkey,
			'Content-Type: application/json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $googlegcmurl);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

		$result = curl_exec($ch); CakeLog::write('vpro', print_r($result, 1));
		if ($result === FALSE) {
			die('Problem occurred: ' . curl_error($ch));
		}
	
		curl_close($ch);
	    }
		
/*
 * feeds 
 * program
 * @post request 
 */
    public function feeds(){
        $programsList = $this->Program->find('all', array(
            'conditions'=>array(
                'Program.feeds_link IS NOT NULL',
                'Program.jockey_user_id'=>$this->Auth->user('id'),
                'Program.broadcasting_date >= "'.date('Y-m-d', strtotime('-1 day')).'"',
                'Program.broadcasting_date <= "'.date('Y-m-d', strtotime('+1 day')).'"'
            ),
            'fields'=>array(
                'Program.id',
                'Program.name',
                'Program.start_time',
                'Program.channel_id'
            ),
            'recursive'=>-1
        ));

        $channels = $this->Program->Channel->find('list');
        $programs = array();

        foreach($programsList as $prg){
            $programs[$prg['Program']['id']] = $prg['Program']['name'].' - '.$prg['Program']['id'].' ( '.$channels[$prg['Program']['channel_id']].' '.date('Y-m-d H:i:s', strtotime($prg['Program']['start_time'])).' ) ';
        }

        $this->set(compact('programs'));

        if(isset($this->request->query['program'])){
            $feeds_file = WWW_ROOT.'feeds'.DIRECTORY_SEPARATOR.$this->request->query['program'].'.json';
            if($feeds_json = file_get_contents($feeds_file)){
                $feeds_json = json_decode($feeds_json);
                $this->request->data['Program'] = get_object_vars($feeds_json[0]);
                $this->Session->setFlash(__('Program Previous Feeds Loaded Successfully'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_success');
            }else{
                $this->Session->setFlash(__('Unable to load Program Previous Feeds!'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_error');
            }
        }
        if($this->request->is('post') ){
		CakeLog::write('vrandesh', print_r($this->request->data, 1));
            if($programs[$this->request->data['Program']['program_id']]){

                foreach($this->request->data['Program']['feeds'] as $key=>$feed){

                    if(!empty($feed['image']['type'])){
                        $file_name = $feed['image']['name'];
                        $tmp_name = $_SERVER['DOCUMENT_ROOT'] . '/ApptarixTV/app/webroot/files/'.$this->request->data['Program']['program_id'].'_feeds/'.$file_name;

                        move_uploaded_file($feed['image']['tmp_name'], $tmp_name);
                        unset($this->request->data['Program']['feeds'][$key]['image']);
                        $this->request->data['Program']['feeds'][$key]['img_file'] = "https://www.teletango.com/ApptarixTV/files/".$this->request->data['Program']['program_id'].'_feeds/'.$file_name;
                    }else{
                        unset($this->request->data['Program']['feeds'][$key]['image']);
                    }
                }


                $feeds_file = WWW_ROOT.'feeds'.DIRECTORY_SEPARATOR.$this->request->data['Program']['program_id'].'.json';
                file_put_contents($feeds_file, "");

                $feeds_json = json_encode(array($this->request->data['Program']));

                if($f = fopen($feeds_file, "w")){
                    fwrite($f, $feeds_json);
                    fclose($f);
                }

                $this->set('selectedProgram',$this->request->data['Program']['program_id']);
                $this->Session->setFlash(__('Feeds Successfully posted'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_success');
            }else{
                $this->Session->setFlash(__('Please select the program'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_error');
            }

            $this->request->data = $this->request->data;
        }

        if(isset($this->request->query['feed_program_id'])){
            $this->set('selectedProgram', $this->request->query['feed_program_id']);

            $feeds_file = WWW_ROOT.'feeds'.DIRECTORY_SEPARATOR.$this->request->query['feed_program_id'].'.json';
            if($feeds_data = file_get_contents($feeds_file)){
                $feeds_data = json_decode($feeds_data, true);
                $this->request->data = array('Program'=>(array)$feeds_data[0]);
            }else{
                CakeLog::write('vab', 'feeds file not found');
            }
        }
    }

    public function enablefeeds($id = null){

        $users = ClassRegistry::init('User')->find('list'); CakeLog::write('vpro', print_r($users, 1));
        $this->set(compact('users'));

        if($this->request->is('post')){
			CakeLog::write('vrandesh', print_r($this->request->data, 1));
            $programDetails = $this->Program->findById($id);
            $programIds = array($id);
            $similarPrograms = $this->Program->find('all', array(
                'conditions'=>array(
                    'Program.name'=>$programDetails['Program']['name'],
                    'Program.feeds_link'=>null,
                    'Program.jockey_user_id'=>null,
                    'Program.jockey_user_name'=>null,
                    'Program.broadcasting_date'=>$programDetails['Program']['broadcasting_date'],
                    'Program.id != '.$id
                ),
                'recursive'=>-1
            ));

            if($similarPrograms){
                foreach($similarPrograms as $prg){
                    $programIds[] = $prg['Program']['id'];
                }
            }

            $count = 0;

            foreach($programIds as $id){
                $feed_dir = WWW_ROOT.'files'.DIRECTORY_SEPARATOR.$id.'_feeds';
                mkdir($feed_dir, 0777);
                $feeds_file = WWW_ROOT.'feeds'.DIRECTORY_SEPARATOR.$id.'.json';
				CakeLog::write('programupdates', print_r($feeds_file, 1));
                if(fopen($feeds_file, "w+")){
                    fclose($feeds_file);
                    chmod($feeds_file, 0777);
                    $data = array();
                    $data['Program']['id'] = $id;
                    $data['Program']['jockey_user_id'] = $this->request->data['Program']['jockey_user_id'];
                    $data['Program']['jockey_user_name'] = $users[$this->request->data['Program']['jockey_user_id']];
                    $data['Program']['feeds_link'] = 'https://www.teletango.com/ApptarixTV/feeds/'.$id.'.json';
                    if($this->Program->save($data)){
                        $count += 1;
                    } 
				CakeLog::write('enablefeedsdata', print_r($data, 1));
                }
            }
            $this->Session->setFlash(__('Feeds enabled for '.$count.' Programs'), 'flashMessages'.DIRECTORY_SEPARATOR.'flash_info');
            $this->redirect(array('controller'=>'programs', 'action'=>'index'));
        }
    }

    public function archiveSevenDaysPrograms(){

        $this->autoRender = false;
        $this->autoLayout = false;

        $date = date('Y-m-d', strtotime('-7 day'));

        $query = 'UPDATE programs SET description = null, thumbnail = null, modified = now() WHERE broadcasting_date = "'.$date.'"';
        echo $query;

        if($this->Program->query($query)){
            echo 'done';
        }
    }

   public function notify_friends($friends) {
        CakeLog::write('notifyfriends','@notify_friends - accessed from checkin');  CakeLog::write('notifyfriends', print_r($friends, 1));
	    $sDate = date("Y-m-d H:i:s");
        foreach($friends as $friend){
            $gcmflag=0;$count=0;
            $friendsList = $this->Program->getFriedsList($friend);
            foreach($friendsList as $myfriend) {
                $status=ClassRegistry::init('AppUser')->find('first', array('conditions'=> array('AppUser.id' => $myfriend,'AppUser.current_program_id >' => 1),'fields'=> array('AppUser.id'),'recursive'=>-1));
				if($status){$count++;}
            } 
            $data = ClassRegistry::init('AppUserNotification')->find('first', array('conditions'=> array('AppUserNotification.app_user_id' => $friend))); 
            CakeLog::write('notifyfriends','@notify_friends - data');  CakeLog::write('notifyfriends', print_r($data, 1));
            if(!$data){ 
				$query = "insert into app_user_notifications (app_user_id,friends_in_programs_count,last_notification,notification_sent,notification_status)
								 values ('$friend','$count','NULL',0,1)";
				$this->Program->query($query);
				$num_sent=0;
			}
			else { 
				   $query = "update app_user_notifications set friends_in_programs_count = '$count' where app_user_id='$friend'";
				   $this->Program->query($query);
				   $num_sent= $data['AppUserNotification']['notification_sent'];
			}
			$old_date = $data['AppUserNotification']['last_notification'];
			$datetime1=new DateTime($old_date); 
			$datetime2=new DateTime($sDate); 
			$interval=$datetime1->diff($datetime2);
			$numhrs=$interval->format('%h');
			if($numhrs > 12) {
				$query = "update app_user_notifications set last_notification='$sDate',notification_sent = '0' where app_user_id='$friend'";
				$this->Program->query($query);
				$num_sent=0;
			}
                                                            
            $pgid=ClassRegistry::init('AppUser')->find('first', array('conditions'=> array('AppUser.id' => $friend),'fields'=> array('AppUser.current_program_id'),'recursive'=>-1));
            if($pgid['AppUser']['current_program_id'] ==NULL){$gcmflag=1;}
			if($count > 3 && $num_sent < 3 && $gcmflag && $data['AppUserNotification']['notification_status'] )
            {  
			    CakeLog::write('notifyfriends','@notify_friends - gcmnotify_friends condition satisfied');
			    $this->gcmnotify_friend($friend);
                $num_sent++;
                $query="update app_user_notifications set last_notification='$sDate',notification_sent='$num_sent' where app_user_id='$friend'";
                $this->Program->query($query);
            }                                               
        }                  
	}

	public function iosnotify_friend($app_user_id) {
        CakeLog::write('friends1', print_r($app_user_id, 1));
	CakeLog::write('vpro','pull check');
        $friendsList = $this->Program->getFriedsList($app_user_id);
        CakeLog::write('friends2', print_r($friendsList, 1));
        foreach($friendsList as $friend) {
			$return=ClassRegistry::init('AppUser')->find('first', array('conditions'=> array('AppUser.id' => $friend,'AppUser.current_program_id >' => 1),'fields'=> array('AppUser.name'),'recursive'=>-1));
            if($return) { $fname=explode(' ', $return['AppUser']['name']);
            $names[]=$fname[0]; }
        }
        CakeLog::write('friends3', print_r($names, 1));
        $rid =  ClassRegistry::init('Gcm')->find('all', array(
                'conditions'=>array(
                    'app_user_id'=>$app_user_id
                ),
                'recursive'=>-1
			));

		$regidList= $rid[0]['Gcm']['gcm_regid']; if(!$regid) { return;}
		CakeLog::write('gcmregids', print_r($regid, 1));
		$message=$names[0];
		array_shift($names);
		$i=0;
		if(count($names) < 3)
		{
			foreach($names as $friend)
			   { $message = $message.",".$name[$i++];}
		}
        else { $message = $message.",".$names[0]." and others";}
        $message=$message." are currently in TeleTango - Join them !!";
        $data= array (
            'type'=> 'notify-friends',
            'program_id' => '1',
            'text'=> $message
        );

		$passphrase = 'gvc3YDGXFu';                
		$ctx = stream_context_create();
		stream_context_set_option($ctx, 'ssl', 'local_cert', '/var/www/ApptarixTV/app/webroot/key/teletango.pem');
		stream_context_set_option($ctx, 'ssl', 'passphrase', $passphrase);
		$fp = stream_socket_client(
				'ssl://gateway.push.apple.com:2195', $err,
				$errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
		if (!$fp)
				exit("Failed to connect: $err $errstr" . PHP_EOL);
		$body['aps'] = array(
				'alert' => 'Friends in Programs',
				'sound' => 'default'
				);
		$body['content']=json_encode($data); //contains message, type and program_id.
		$payload = json_encode($body);
		foreach($regidList as $regidskey=>$regidsvalue){
				$regidList[$regidsvalue] = $regidsvalue; CakeLog::write('gcmregids','payload regids - @iosnotify_friends'); CakeLog::write('gcmregids', print_r($gcmregidList, 1));
				$msg = chr(0) . pack('n', 32) . pack('H*', $regidList[$regidsvalue]) . pack('n', strlen($payload)) . $payload;
				$result = fwrite($fp, $msg, strlen($msg));
		}
		if (!$result)
				echo 'Message not delivered' . PHP_EOL;
		else
				echo 'Message successfully delivered' . PHP_EOL;
		fclose($fp);
	}
	
	
	public function gcmnotify_friend($app_user_id) {
		CakeLog::write('friends1', print_r($app_user_id, 1));
        $friendsList = $this->Program->getFriedsList($app_user_id);
		CakeLog::write('friends2', print_r($friendsList, 1));
        foreach($friendsList as $friend) {
            $return=ClassRegistry::init('AppUser')->find('first', array('conditions'=> array('AppUser.id' => $friend,'AppUser.current_program_id >' => 1),'fields'=> array('AppUser.name'),'recursive'=>-1));
			if($return) { 
				$fname=explode(' ', $return['AppUser']['name']);
				$names[]=$fname[0];
			}
        } 
		CakeLog::write('friends3', print_r($names, 1));
        $rid =  ClassRegistry::init('Gcm')->find('all', array(
                'conditions'=>array(
					'app_user_id'=>$app_user_id
                ),
                'recursive'=>-1
        ));

		$regid= $rid[0]['Gcm']['gcm_regid']; if(!$regid) { return;}
		$message=$names[0];
		//CakeLog::write('friends', print_r($message, 1));
		array_shift($names);
		$i=0;
		if(count($names) < 3)
		{
                foreach($names as $friend)
                  { $message = $message.",".$name[$i++];}
        } 
        else { $message = $message.",".$names[0]." and others";}
        $message=$message." are currently in TeleTango - Join them !!";
	
		$data= array (
			'type'=> 'Program',
			'program_id' => '1',
			'text'=> $message
        );

		$fields = array(
            'registration_ids'  => array($regid),
            'delay_while_idle' => false,
            'data'              => array( "message" => $data )
        );

        $googlegcmkey = Configure::read('gcm.google_apikey');
        $googlegcmurl = Configure::read('gcm.google_gcmurl');

        $headers = array(
                'Authorization: key=' .$googlegcmkey ,
                'Content-Type: application/json'
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $googlegcmurl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

        $result = curl_exec($ch);
        if ($result === FALSE) {
                die('Problem occurred: ' . curl_error($ch));
        }

        curl_close($ch);
	}


 
}

