<?php

namespace app\modules\v1\controllers;

use common\models\ApiLog;
use common\models\AppShare;
use common\models\AppVersion;
use common\models\AskedDaily;
use common\models\Friends;
use common\models\Intercession;
use common\models\IntercessionCommentPraise;
use common\models\IntercessionComments;
use common\models\IntercessionJoin;
use common\models\IntercessionStatistics;
use common\models\IntercessionUpdate;
use common\models\ReadingTime;
use common\models\ReciteRecord;
use common\models\ShareToday;
use common\models\SyncContactsRecord;
use React\Promise\FunctionRaceTest;
use yii;
use common\models\NickList;
use common\models\Portrait;
use common\models\SmsRegisterBinding;
use common\models\User;
use common\models\UserNickBinding;
use yii\web\Controller;
use yii\base\Exception;
use yii\helpers\ArrayHelper;

class ApiController extends Controller
{
    protected $startMemory;
    protected $startTime;

    public function beforeAction($action)
    {
        parent::beforeAction($action);

        //初始化内存、时间，用于计算内存消耗
        $this->startMemory = memory_get_usage();
        $this->startTime = microtime(true);

        //验证签名
        //在Module中声明不进行基本验证的也不需签名
        $behavior = $this->module->getBehavior('authenticator');
        if(!isset($behavior->except)) {
            return true;
        }
        $except = $behavior->except;
        $route = sprintf('%s/%s', yii::$app->controller->id, $this->action->id);
        if(in_array($route, $except)){
            return true;
        }

        //指定ip不需签名
        if(in_array(yii::$app->request->getUserIP(), yii::$app->params['WithoutVerifyIP'])){
            return true;
        }
        $sign = isset($_REQUEST['sign']) ? $_REQUEST['sign'] : null;
        if(!$sign)
            $this->code(412, '签名错误');
        unset($_REQUEST['sign']);
        $secretKey = yii::$app->params['Authorization']['sign']['secret_key'];
        if(!yii::$app->sign->validate($_REQUEST, $sign, $secretKey))
            $this->code(412, '签名错误', ['sign' => $sign]);

        //验证时间戳
        $timestamp = isset($_REQUEST['timestamp']) ? $_REQUEST['timestamp'] : null;
        if(!$timestamp)
            $this->code(406, '请求已过期');

        return true;
    }

    /**
     * 用户登录
     * @param int $nation_code
     * @param $phone
     * @param $password
     */
    public function actionUserLogin($nation_code = 86, $phone, $password)
    {
        try{
            $userInfo = User::findByPhoneAndPassword($nation_code, $phone, $password);
            if(!$userInfo) {
                $this->code(450, '账号或密码错误');
            }

            //返回用户信息
            //获取头像
            $portraitInfo = Portrait::findByUserId($userInfo['id']);
            $avatar = $portraitInfo ? yii::$app->qiniu->getDomain() . '/' . $portraitInfo['portrait_name'] : '';

            //获取用户标识
            $nickInfo = UserNickBinding::findNickInfoByUserId($userInfo['id']);
            if(!$nickInfo) throw new Exception('未找到用户标识');

            //获取阅读时间
            $readInfo = ReadingTime::findByUserId($userInfo['id']);

            //获取分享统计次数
            $shareInfo = AppShare::findByUserId($userInfo['id']);

            //上次代祷时间
            $statistics = IntercessionStatistics::findWithUserId($userInfo['id']);

            //获取总参加代祷次数
            $totalJoinIntercession = IntercessionJoin::findTotalWithUserId($userInfo['id']);

            //返回用户数据
            $this->code(200, 'ok', [
                'user_id' => $userInfo['id'],
                'nation_code' => $userInfo['nation_code'],
                'avatar' => $avatar,
                'phone' => $userInfo['username'],
                'nick_name' => $userInfo['nickname'],
                'nick_id' => $nickInfo['nickList']['nick_id'],
                'gender' => (int)$userInfo['gender'],
                'birthday' => $userInfo['birthday'],
                'believe_date' => $userInfo['believe_date'],
                'province_id' => $userInfo['province_id'],
                'city_id' => $userInfo['city_id'],
                'province_name' => $userInfo['province_name'],
                'city_name' => $userInfo['city_name'],
                'continuous_interces_days' => (int)$statistics['continuous_interces_days'],    //连续代祷天数
                'continuous_days' => $readInfo ? $readInfo['continuous_days'] : 0,    //连续阅读天数
                'total_minutes' => $readInfo ? $readInfo['total_minutes'] : 0,    //总阅读分钟数
                'total_share_times' => isset($shareInfo['share_times']) ? $shareInfo['share_times'] : 0,    //分享统计次数
                'yesterday_minutes' => (int)$readInfo['yesterday_minutes'],
                'today_minutes' => (int)$readInfo['today_minutes'],
                'last_read_long' => (int)$readInfo['last_read_long'],
                'last_interces_time' => isset($statistics['last_interces_time']) ? (int)$statistics['last_interces_time'] : 0,
                'total_join_intercession' => (int)$totalJoinIntercession,
            ]);

        }catch (yii\base\Exception $e){
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 用户注册
     * @param $nation_code
     * @param $phone
     * @param $sms_code
     * @param $password
     */
    public function actionUserRegister($nation_code = '86', $phone, $sms_code, $password)
    {
        try{
            //验证码正确性
            $sms = new SmsRegisterBinding();
            $result = $sms->validateSmsCode($nation_code, $phone, $sms_code, 1800);
            if(!$result)
                $this->code(450, '验证码不存在或已过期');

            //验证用户是否已存在
            $user = new User();
            $result = $user->isExists($nation_code, $phone);
            if($result)
                $this->code(452, '已经注册');

            //同步账号到腾讯云
            $result = yii::$app->tencent->accountImport(sprintf('%s-%s', $nation_code, $phone));
            if(0 != $result['ErrorCode']) throw new Exception('腾讯云同步错误');

            //开启事务
            $trans = yii::$app->db->beginTransaction();

            //添加新用户
            $userId = $user->add([
                'nation_code' => $nation_code,
                'username' => $phone,
                'password' => $password,
                'created_at' => time(),
                'updated_at' => time()
            ]);
            if(!$userId) {
                $trans->rollBack();
                throw new Exception('用户入库失败');
            }

            //选择用户标识入库
            $nickListObj = new NickList();
            $nickInfo = $nickListObj->getInfoByOrderNo($userId);
            if(!$nickInfo){
                $trans->rollBack();
                throw new Exception('未找到用户标识');
            }
            $userNickObj = new UserNickBinding();
            $result = $userNickObj->add([
                'user_id' => $userId,
                'nick_list_id' => $nickInfo['id'],
                'create_at' => time()
            ]);
            if(!$result) {
                $trans->rollBack();
                throw new Exception('nick_id入库失败');
            }
            $trans->commit();
            $this->code(200, 'ok', [
                'user_id' => $userId,
                'nation_code' => $nation_code,
                'avatar' => '',
                'phone' => $phone,
                'nick_name' => '',
                'nick_id' => $nickInfo['nick_id'],
                'gender' => 0,
                'birthday' => '',
                'believe_date' => '',
                'province_id' => 0,
                'city_id' => 0,
                'province_name' => '',
                'city_name' => '',
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 获取注册验证码
     * @param　int $phone 手机号
     * @param int $nation_code　国家码
     */
    public function actionRegisterCode($phone, $nation_code = 86)
    {
        try{
            if(User::findByUsernameAndNationCode($phone, $nation_code))
                $this->code(450, '用户已经注册');

            //发短信
            $code = rand(100000, 999999);
            $resultArray = yii::$app->tencent->sendSMS($phone, sprintf('【活石APP】%s为您的登录验证码，请于30分钟内填写。如非本人操作，请忽略本短信。', $code), "86");
            if($resultArray['result'] != 0) throw new Exception('短信发送失败');

            //记录短信
            $smsBinding = new SmsRegisterBinding();
            $result = $smsBinding->add([
                'nation_code' => $nation_code,
                'phone' => $phone,
                'code' => $code,
                'status' => 1,
                'create_at' => time(),
            ]);
            if(!$result) throw new Exception('smsRegisterBinding save error');

            $this->code(200, 'OK');

        }catch (yii\base\Exception $e){
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 上传用户头像
     * @param int $user_id 用户id
     */
//    public function actionUploadPortrait($user_id)
//    {
//        try {
//            $fileName = md5(time() . uniqid());
//            $filePath = sprintf('%s/resources/upload/%s.jpg', yii::$app->basePath, $fileName);
//
//            //获取文件流并保存
//            $upload = new yii\web\UploadedFile();
//            $instance = $upload->getInstanceByName('portrait');
//            if(null == $instance) {
//                $this->code(450, '未收到图片流');
//            }
//            $is = $instance->saveAs($filePath, true);
//            if(!$is) throw new Exception('图片上传失败');
//
//            //图片同步七牛
//            $qiniuObj = yii::$app->qiniu;
//            $is = $qiniuObj->upload($filePath, null, ['callbackUrl' => $qiniuObj->getCallbackUrl(), 'callbackBody' => "key=$fileName&user_id=1", 'saveKey' => $fileName]);
//            if(!$is) throw new Exception(yii::$app->qiniu->getError());
//
//            //图片入库
////            $portrait = new Portrait();
////            $is = $portrait->add([
////                'user_id' => $user_id,
////                'portrait_name' => $fileName,
////                'created_at' => time(),
////            ]);
////            if(!$is) throw new Exception('图片入库失败');
//
//            //删除临时文件
//            $is = unlink($filePath);
//            if(!$is) throw new Exception('临时文件删除失败');
//
//            $this->code(200, 'ok', ['url' => yii::$app->qiniu->getDomain() . '/' . $fileName]);
//
//        }catch (Exception $e) {
//            $this->code(500, $e->getMessage());
//        }
//    }

    /**
     * 获取七牛上传凭证
     * @param int $user_id
     */
    public function actionQiniuToken($user_id)
    {
        try {
            //生成文件名
            $fileName = md5(time() . uniqid());

            //生成token
            $qiniuObj = yii::$app->qiniu;
            $token = $qiniuObj->generateToken(['callbackUrl' => $qiniuObj->getCallbackUrl(), 'callbackBody' => "key=$fileName&user_id=$user_id", 'saveKey' => $fileName]);
            if(!$token) throw new Exception('token 获取失败');

            $this->code(200, 'ok', ['token' => $token]);

        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 七牛头像上传回调
     * @param $user_id
     * @param string $key 文件名称
     */
    public function actionQiniuCallback($user_id, $key)
    {
        try{
            //验证是否为七牛回调
            $contentType = $_SERVER['HTTP_CONTENT_TYPE'];
            $authorization = $_SERVER['HTTP_AUTHORIZATION'];
            $url = yii::$app->qiniu->getCallbackUrl();
            $body = http_build_query($_POST);
            $is = yii::$app->qiniu->verifyCallback($contentType, $authorization, $url, $body);
            if(!$is) {
                yii::info('请求不合法', 'qiniu-callback');
                $this->code(450, '请求不合法');
            }

            //图片入库
            $portrait = new Portrait();
            $is = $portrait->add([
               'user_id' => $user_id,
               'portrait_name' => $key,
               'created_at' => time(),
            ]);
            if(!$is) throw new Exception(var_export($portrait->getErrors(), true));
            $this->code(200, 'ok', [
                'avatar' => sprintf('%s/%s', yii::$app->qiniu->getDomain(), $key),
            ]);

        }catch (Exception $e) {
            yii::info($e->getMessage(), 'qiniu-callback');
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 完善用户资料
     * @param $user_id
     * @param $nick_name
     * @param $gender
     * @param $birthday
     * @param $believe_date
     * @param int $province_id
     * @param int $city_id
     * @param string $province_name
     * @param string $city_name
     */
    public function actionUserData($user_id, $nick_name, $gender, $birthday, $believe_date, $province_id = 0, $city_id = 0, $province_name = '', $city_name = '')
    {
        try{
            if(!strtotime($believe_date) || !strtotime($birthday)) {
                $this->code(450, '日期格式不正确');
            }
            if(1 != $gender && 0 != $gender) {
                $this->code(451, '`gender`错误');
            }

            //检查是否未注册
            $userInfo = User::findIdentity($user_id);
            if(!$userInfo) {
                $this->code(452, '账号未注册');
            }

            //修改信息
            $is = User::mod([
                'nickname' => $nick_name,
                'gender' => $gender,
                'birthday' => $birthday,
                'believe_date' => $believe_date,
                'updated_at' => time(),
                'province_id' => $province_id,
                'city_id' => $city_id,
                'province_name' => $province_name,
                'city_name' => $city_name,
            ], $userInfo['id']);
            if(!$is) throw new Exception('用户资料修改失败');

            //返回用户信息
            //获取头像
            $portraitInfo = Portrait::findByUserId($user_id);
            $avatar = $portraitInfo ? yii::$app->qiniu->getDomain() . '/' . $portraitInfo['portrait_name'] : '';

            //获取用户标识
            $nickInfo = UserNickBinding::findNickInfoByUserId($user_id);
            if(!$nickInfo) throw new Exception('未找到用户标识');

            //返回用户数据
            $this->code(200, 'ok', [
                'user_id' => $user_id,
                'nation_code' => $userInfo['nation_code'],
                'avatar' => $avatar,
                'phone' => $userInfo['username'],
                'nick_name' => $nick_name,
                'nick_id' => $nickInfo['nickList']['nick_id'],
                'gender' => (int)$gender,
                'birthday' => date('Y-m-d', strtotime($birthday)),
                'believe_date' => $believe_date,
                'province_id' => $province_id,
                'city_id' => $city_id,
                'province_name' => $province_name,
                'city_name' => $city_name,
            ]);

        }catch (yii\base\Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 阅读时间
     * @param $user_id
     * @param $last_minutes 上次阅读分钟数
     * @param $continuous_days 连续天数
     * @param $total_minutes 总阅读天数
     * @param $is_add true-覆盖数据库 false-不覆盖数据库
     * @param int $yesterday_minutes 昨日阅读时间
     * @param int $today_minutes 今日阅读时间
     * @param int $last_read_long 上次阅读结束时间
     */
    public function actionReadingTime($user_id, $last_minutes, $continuous_days, $total_minutes, $is_add, $yesterday_minutes = 0, $today_minutes = 0, $last_read_long = 0)
    {
        try {
            if($last_minutes < 0 || $continuous_days < 0) {
                $this->code(450, '时间必须是正整数');
            }

            $notice = '您真是一个虔诚的教徒，希望您再接再厉';

            //空的就新增，已存在则修改
            $info = ReadingTime::findByUserId($user_id);
            if($info) {

                //不覆盖就直接返回原数据
                if('false' === $is_add) {
                    $this->code(200, 'ok', [
                        'continuous_days' => $info['continuous_days'],
                        'last_minutes' => $info['last_minutes'],
                        'total_minutes' => $info['total_minutes'],
                        'notice' => $notice,
                        'yesterday_minutes' => (int)$info['yesterday_minutes'],
                        'today_minutes' => (int)$info['today_minutes'],
                        'last_read_long' => (int)$info['last_read_long'],
                    ]);
                }

                //覆盖原数据
                $is = ReadingTime::mod([
                    'total_minutes' => (int)$total_minutes,
                    'continuous_days' => (int)$continuous_days,
                    'last_minutes' => (int)$last_minutes,
                    'yesterday_minutes' => (int)$yesterday_minutes,
                    'today_minutes' => (int)$today_minutes,
                    'updated_at' => time(),
                    'last_read_long' => (int)$last_read_long,
                ], $user_id);
                if(!$is) throw new Exception('阅读统计修改失败');

            }else {
                $readingTime = new ReadingTime();
                $is = $readingTime->add([
                    'user_id' => $user_id,
                    'total_minutes' => (int)$total_minutes,
                    'continuous_days' => (int)$continuous_days,
                    'last_minutes' => (int)$last_minutes,
                    'yesterday_minutes' => (int)$yesterday_minutes,
                    'today_minutes' => (int)$today_minutes,
                    'created_at' => time(),
                    'updated_at' => time(),
                    'last_read_long' => (int)$last_read_long,
                ]);
                if(!$is) throw new Exception('阅读统计保存失败');
            }
            $this->code(200, 'ok', [
                'continuous_days' => $continuous_days,
                'last_minutes' => $last_minutes,
                'total_minutes' => $total_minutes,
                'yesterday_minutes' => (int)$yesterday_minutes,
                'today_minutes' => (int)$today_minutes,
                'notice' => $notice,
                'last_read_long' => (int)$last_read_long,
            ]);

        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 同步联系人
     * @param $user_id
     * @param $contacts
     * @internal param $phones
     */
    public function actionContacts($user_id, $contacts = '')
    {
        try {
            $data = [];

            //如果`contacts`为空则直接返回好友列表
            if(!$contacts) {
                $friendList = Friends::findAllInfoByUserId($user_id);
                if($friendList) {
                    foreach($friendList as $friendInfo) {
                        $data[] = [
                            'phones' => md5($friendInfo['username']),
                        ];
                    }
                }
                $this->code(200, 'ok', $data);
            }

            $contactsArray = json_decode($contacts);
            if(!$contactsArray || !is_array($contactsArray)) {
                $data = [];

                $this->code(200, 'ok', []);
            }

            //区分用户类型：已注册、未注册
            foreach($contactsArray as $obj) {

                $phoneArray = explode(',', $obj->phones);
                if(!$phoneArray)
                    continue;
                $phoneArray = array_filter(array_unique($phoneArray));
                $isFriend = 0;
                foreach($phoneArray as $phone) {

                    //清除空格
                    $phone = trim($phone);

                    //未注册则跳过
                    $userInfo = User::findByUsernameAndNationCode($phone, 86);
                    if(isset($userInfo['id']) && $userInfo['id'] == $user_id) {
                        continue;
                    }

                    //已注册则添加为好友
                    if(0 != $userInfo['id']) {
                        $isFriend = 1;

                        //如果还不是好友则添加
                        $friendInfo = Friends::findByFriendIdAndUserId($userInfo['id'], $user_id);
                        if(!$friendInfo) {
                            $friends = new Friends();
                            $is = $friends->add([
                                'user_id' => $user_id,
                                'friend_user_id' => $userInfo['id'],
                                'created_at' => time(),
                                'updated_at' => time(),
                            ]);
                            if(!$is) throw new Exception('好友添加失败');
                        }
                    }
                }

                //构造返回数据
                $data[] = [
                    'contacts_id' => $obj->contacts_id,
                    'contacts_name' => $obj->contacts_name,
                    'phones' => $obj->phones,
                    'contacts_type' => $isFriend ? 1 : 2,
                ];
            }

            //设置`该用户已同步过通讯录`的状态
            $sync = new SyncContactsRecord();
            $sync->add([
                'user_id' => $user_id,
                'created_at' => time(),
            ]);

            //返回
            $this->code(200, 'ok', $data);

        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 发布代祷
     * @param $user_id
     * @param $content
     * @param $privacy 是否私有 0-否 1-是
     * @param null $updated_at 预计更新时间，时间戳
     * @param $position 定位位置
     */
    public function actionIntercession($user_id, $content, $privacy, $updated_at = null, $position = null)
    {
        try {
            if(empty($content)) {
                $this->code(450, '代祷内容不能为空');
            }
            if($privacy != 1 && $privacy != 0) {
                $this->code(451, '参数格式不正确');
            }

            //入库
            $intercession = new Intercession();
            $is = $intercession->add([
                'user_id' => $user_id,
                'content' => $content,
                'privacy' => (int)$privacy,
                'created_at' => time(),
                'updated_at' => $updated_at/1000,
                'ip' => yii::$app->request->getUserIP(),
                'comments' => 0,
                'intercessions' => 0,
                'position' => $position,
            ]);
            if(!$is) throw new Exception(json_encode($intercession->getErrors()));

            if($updated_at) {
                try {
                    //推送消息
                    $payload = yii::$app->jPush->push()
                        ->setPlatform('all')
                        ->addAlias($user_id . '')
                        ->addAndroidNotification('你发的代祷到了约定的更新时间，经常性的及时更新祷告事项的最新进展能让弟兄姊妹们更有信心。', null, 1, ['message_id' => $is, 'message_type' => 1])
                        ->addIosNotification('你发的代祷到了约定的更新时间，经常性的及时更新祷告事项的最新进展能让弟兄姊妹们更有信心。', null, null, null, null, ['message_id' => $is, 'message_type' => 1])
                        ->build();

                    //创建定时任务
                    $response = yii::$app->jPush->schedule()->createSingleSchedule("代祷到了约定的更新时间", $payload, array("time"=>date('Y-m-d H:i:s', $updated_at/1000)));
                    if(!$response) {
                        $this->code(500, '定时推送任务创建失败');
                    }

                }catch (\Exception $e) {
                    if(8 != $e->getCode()) {
                        $this->code(500, $e->getMessage());
                    }
                }
            }

            $this->code(200);

        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 代祷列表
     * @param $user_id
     * @param int $start_page
     * @param int $page_no
     * @param int $intercession_type 0-所有人的代祷|1-我的代祷|2-我参与的代祷
     */
    public function actionIntercessionList($user_id, $start_page = 1, $page_no = 10, $intercession_type = 0)
    {
        try {
            if(1 == $intercession_type) {
                $data = $this->myIntercessionList($user_id, $start_page, $page_no);
                $this->code(200, 'ok', $data);
            }

            if(2 == $intercession_type) {
                $data = $this->myJoinIntercessionList($user_id, $start_page, $page_no);
                $this->code(200, 'ok', $data);
            }

            //查询三维内的好友id
            $allIdArray = Friends::findFriendsByUserIdAndDepth($user_id, 3);

            //处理数组
            $handleArray = [];
            foreach($allIdArray as $v) {
                $handleArray[$v['depth']][] = $v['friend_user_id'];
            }
            $userIdArray = $newArray = [];
            foreach($handleArray as $k => $handleIdArray) {
                $handleIdArray = array_unique($handleIdArray);
                if(1 != $k) {
                    foreach($handleIdArray as $handleKey => $handleValue) {
                        $handleArray[1] = isset($handleArray[1]) ? $handleArray[1] : [];
                        $handleArray[2] = isset($handleArray[2]) ? $handleArray[2] : [];
                        if(2 == $k && (in_array($handleValue, $handleArray[1]) || $handleValue == $user_id)) {
                            unset($handleIdArray[$handleKey]);
                        }
                        if(3 == $k && (in_array($handleValue, $handleArray[1]) || in_array($handleValue, $handleArray[2]) || $handleValue == $user_id)) {
                            unset($handleIdArray[$handleKey]);
                        }
                    }
                }
                $newArray[$k] = $handleIdArray;
                $userIdArray = array_merge($userIdArray, $handleIdArray);
            }

            //获取代祷内容列表
            $userIdArray = array_merge($userIdArray, [$user_id]);
            $intercessionList = Intercession::findAllByFriendsId(implode(',', $userIdArray), $start_page, $page_no);

            //分类数据
            $data = [];
            foreach($intercessionList as $v) {

                //根据用户id查询关系
                $relationship = 0;
                foreach($newArray as $kUser => $vUser) {
                    if($user_id == $v['user_id']) {
                        $relationship = 0;
                    }
                    if(in_array($v['user_id'], $vUser)) {
                        $relationship = $kUser;
                    }
                }

                //获取最新头像
                $portraitInfo = Portrait::findByUserId($v['user_id']);

                //获取代祷更新列表
                $updateList = IntercessionUpdate::getListWithIntercessionId($v['id']);
                $resultUpdateList = [];
                foreach($updateList as $updateInfo) {
                    $resultUpdateList[] = [
                        'content' => $updateInfo['content'],
                        'create_time' => $updateInfo['created_at'] * 1000,
                    ];
                }
                $resultUpdateList = array_merge($resultUpdateList, [[
                    'content' => $v['content'],
                    'create_time' => $v['created_at'] * 1000,
                ]]);

                //获取代祷勇士
                $intercessorsList = IntercessionJoin::getAllByIntercessionId($v['id']);
                $resultIntercessorsList = [];
                foreach($intercessorsList as $intercessorsInfo) {
                    $resultIntercessorsList[] = [
                        'user_id' => $intercessorsInfo['id'],
                        'nick_name' => $intercessorsInfo['nickname'],
                    ];
                }

                //是否已经加入代祷
                $intercessionJoinInfo = IntercessionJoin::findByIntercessionIdAndIntercessorsId($v['id'], $user_id);

                //构造返回数据
                $data[] = [
                    'user_id' => $v['user_id'],
                    'intercession_id' => $v['id'],
                    'content_list' => $resultUpdateList,
                    'intercession_number' => $v['intercessions'],
                    'avatar' => !$portraitInfo ? '' : yii::$app->qiniu->getDomain() . '/' .$portraitInfo['portrait_name'],
                    'nick_name' => $v['nickname'],
                    'time' => $v['created_at'] * 1000,
                    'relationship' => $relationship,
                    'position' => $v['position'],
                    'intercessors_list' => $resultIntercessorsList,
                    'is_interceded' => $intercessionJoinInfo ? true : false,
                    'gender' => (int)$v['gender'],
                ];
            }
            $this->code(200, 'ok', $data);

        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 活石`tab`页面内容
     * @param $user_id
     * @param int $continuous_interces_days 连续代祷天数
     * @param int $last_interces_time 上次代祷时间
     */
    public function actionHuoshiTab($user_id, $continuous_interces_days = 0, $last_interces_time = 0)
    {
        try{
            $newInfo = ShareToday::findNewInfo();
            if(!$newInfo) {
                $this->code(450, '没有分享内容');
            }

            //删除旧的统计数据
            //写入新代祷统计数据
            IntercessionStatistics::deleteInfo($user_id);
            $intercessionStatistics = new IntercessionStatistics();
            $intercessionStatistics->add([
                'user_id' => $user_id,
                'continuous_interces_days' => $continuous_interces_days,
                'last_interces_time' => $last_interces_time,
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            //连续阅读天数
            $readingInfo = ReadingTime::findByUserId($user_id);

            $this->code(200, '', [
                'continuous_interces_days' => $continuous_interces_days,    //连续代祷天数
                'last_interces_time' => $last_interces_time,    //上次代祷时间
                'continuous_days' => isset($readingInfo['continuous_days']) ? $readingInfo['continuous_days'] : 0,    //连续阅读天数
                'share_number' => $newInfo['share_number'],
                'share_today' => $newInfo['share_content'],
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 检测是否有权限访问代祷页面
     * 目前判定标准是通讯录好友数量大于等于`3`人则可以访问
     * @param $user_id
     */
    public function actionPermission($user_id)
    {
        try{
            //查询好友数量
            $friendsArray = Friends::findAllByUserId($user_id);
            $permission = 1;
            if(!$friendsArray || 3 > count($friendsArray)) {
                $permission = 0;
            }

            //是否已同步过通讯录
            $syncInfo = SyncContactsRecord::findByUserId($user_id);

            //返回
            $this->code(200, 'ok', [
                'permission' => $permission,
                'is_synced' => $syncInfo ? 1 : 0,
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 获取评论
     * @param $user_id
     * @param $intercession_id
     * @param int $start_page
     */
    public function actionIntercessionComments($user_id, $intercession_id, $start_page = 1)
    {
        try{
            $list = IntercessionComments::getAllByIntercessionId($intercession_id, $start_page, 10);
            $data = [];
            if($list) {
                foreach($list as $info) {

                    //该用户是否点过赞
                    $is = IntercessionCommentPraise::findWithCommentId($info['id'], $user_id);

                    //获取用户资料
                    $commentUserInfo = User::getUserInfoAndAvastar($info['comment_by_id']);
                    $data[] = [
                        'comment_id' => $info['id'],
                        'user_id' => $info['comment_by_id'],
                        'content' => $info['content'],
                        'avatar' => !empty($commentUserInfo['portrait_name']) ? yii::$app->qiniu->getDomain() . '/' . $commentUserInfo['portrait_name'] : '',
                        'nick_name' => $commentUserInfo['nickname'],
                        'praise_number' => $info['praise_number'],
                        'created_at' => $info['created_at'] * 1000,
                        'is_praised' => $is ? 1 : 0,
                        'gender' => (int)$commentUserInfo['gender'],
                    ];
                }
            }

            $this->code(200, 'ok', $data);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 分享统计
     * @param $user_id
     */
    public function actionShareRecording($user_id)
    {
        try {
            //获取分享次数
            $appShare = new AppShare();
            $info = AppShare::findByUserId($user_id);

            if(!$info) {
                $appShare->add([
                    'user_id' => $user_id,
                    'share_times' => 1,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            }else {
                //累加分享次数
                $appShare = new AppShare();
                $appShare->accumulation($user_id);
            }

            //返回
            $this->code(200, '', [
                'total_share_times' => isset($info['share_times']) ? intval($info['share_times']) + 1 : 1,
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 加入代祷接口
     * @param $user_id
     * @param $intercession_id
     * @param 连续代祷天数|int $continuous_interces_days 连续代祷天数
     * @param 上次代祷时间|int $last_interces_time 上次代祷时间
     * @throws \common\models\Exception
     */
    public function actionIntercessionJoin($user_id, $intercession_id, $continuous_interces_days = 0, $last_interces_time = 0)
    {
        $trans = yii::$app->db->beginTransaction();

        //查询是否已加入代祷
        $info = IntercessionJoin::findByIntercessionIdAndIntercessorsId($intercession_id, $user_id);
        if($info) {
            $this->code(450, '已加入过该代祷');
        }

        //获取用户信息
        $userInfo = User::findIdentity($user_id);
        if(!$userInfo) {
            $this->code(500, '登录用户不存在');
        }

        //查询是否有代祷内容
        $interInfo = Intercession::findByIntercessionId($intercession_id);
        if(!$interInfo) {
            $this->code(451, '代祷内容不存在');
        }

        //查询该用户总共参与了多少次代祷
        $total = IntercessionJoin::findTotalWithIntercessorsId($user_id);

        try {
            //加入代祷入库
            $intercessionJoin = new IntercessionJoin();
            $is = $intercessionJoin->add([
                'intercession_id' => $intercession_id,
                'intercessors_id' => $user_id,
                'user_id' => $interInfo['user_id'],
                'created_at' => time(),
                'updated_at' => time(),
                'ip' => yii::$app->request->getUserIP(),
            ]);
            if(!$is)
                throw new Exception('数据入库失败');

            //更新代祷表中的代祷数
            $intercession = new Intercession();
            $intercession->increaseIntercessions($intercession_id);

            //删除旧的统计数据
            //写入新代祷统计数据
            IntercessionStatistics::deleteInfo($user_id);
            $intercessionStatistics = new IntercessionStatistics();
            $intercessionStatistics->add([
                'user_id' => $user_id,
                'continuous_interces_days' => $continuous_interces_days,
                'last_interces_time' => $last_interces_time,
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            try {
                //推送消息
                if($interInfo['user_id'] != $user_id) {
                    yii::$app->jPush->push()
                        ->setPlatform('all')
                        ->addAlias($interInfo['user_id'] . '')
                        ->addAndroidNotification($userInfo['nickname'] . '已经为你代祷，点击查看。', null, 1, ['message_id' => $intercession_id, 'message_type' => 2])
                        ->addIosNotification($userInfo['nickname'] . '已经为你代祷，点击查看。', null, null, null, null, ['message_id' => $intercession_id, 'message_type' => 2])
                        ->send();
                }
            }catch (\Exception $e) {
                if(1011 != $e->getCode()) {
                    $trans->rollBack();
                    $this->code(500, $e->getMessage());
                }
            }

            //返回
            $trans->commit();

            $this->code(200, '', [
                'continuous_interces_days' => $continuous_interces_days,
                'last_interces_time' => $last_interces_time,
                'total_join_intercession' => $total,
            ]);
        }catch (\Exception $e) {
            $trans->rollBack();
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 更新代祷内容
     * @param $user_id
     * @param $intercession_id
     * @param $content
     */
    public function actionIntercessionUpdate($user_id, $intercession_id, $content)
    {
        try {
            //查询是否有代祷内容
            $interInfo = Intercession::findByIntercessionId($intercession_id);
            if(!$interInfo) {
                $this->code(451, '代祷内容不存在');
            }

            //代祷是否属于该用户
            if($interInfo['user_id'] !== (int)$user_id) {
                $this->code(452, '无权更新该代祷');
            }

            //新增更新代祷内容
            $update = new IntercessionUpdate();
            $update->add([
                'intercession_id' => $intercession_id,
                'content' => $content,
                'ip' => yii::$app->request->getUserIP(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            //获取代祷勇士
            $intercessorsList = IntercessionJoin::getAllByIntercessionId($intercession_id);
            $resultIntercessorsList = [];
            foreach($intercessorsList as $intercessorsInfo) {
                //排除自己
                if($intercessorsInfo['id'] != $user_id) {
                    $resultIntercessorsList[] = $intercessorsInfo['id'] . '';
                }
            }

            try {
                //推送消息
                yii::$app->jPush->push()
                    ->setPlatform('all')
                    ->addAlias($resultIntercessorsList)
                    ->addAndroidNotification('你参与的代祷有了最新进展，赶紧去看看。', null, 1, ['message_id' => $intercession_id, 'message_type' => 4])
                    ->addIosNotification('你参与的代祷有了最新进展，赶紧去看看。', null, null, null, null, ['message_id' => $intercession_id, 'message_type' => 4])
                    ->send();
            }catch (\Exception $e) {
                if(1011 != $e->getCode()) {
                    $this->code(500, $e->getMessage());
                }
            }

            //返回
            $this->code(200);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 点赞
     * @param $user_id
     * @param $comment_id
     */
    public function actionIntercessionCommentsPraise($user_id, $comment_id)
    {
        $trans = yii::$app->db->beginTransaction();
        try {
            //评论是否存在
            $commentInfo = IntercessionComments::findWithCommentId($comment_id);
            if(!$commentInfo) {
                $this->code(451, '评论不存在');
            }

            //检测是否已经点赞
            $praiseInfo = IntercessionCommentPraise::findWithCommentId($comment_id, $user_id);
            if($praiseInfo) {
                //取消
                IntercessionCommentPraise::cancel($comment_id, $user_id);

                //递减评论表点赞数量
                $comment = new IntercessionComments();
                $comment->decreasePraiseNumber($comment_id);
            }else {
                //新增
                $praise = new IntercessionCommentPraise();
                $praise->add([
                    'comment_id' => $comment_id,
                    'praise_user_id' => $user_id,
                    'user_id' => $commentInfo['comment_by_id'],
                    'created_at' => time(),
                    'updated_at' => time(),
                    'ip' => yii::$app->request->getUserIP(),
                ]);

                //递增评论表点赞数量
                $comment = new IntercessionComments();
                $comment->increasePraiseNumber($comment_id);
            }

            //返回
            $trans->commit();
            $this->code(200, 'ok', ['status' => $praiseInfo ? false : true]);
        }catch (Exception $e) {
            $trans->rollBack();
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 评论代祷
     * @param $user_id
     * @param $intercession_id
     * @param $content
     */
    public function actionIntercessionCommentsPublication($user_id, $intercession_id, $content)
    {
        $trans = yii::$app->db->beginTransaction();
        try {
            //获取用户信息
            $userInfo = User::findIdentity($user_id);
            if(!$userInfo) {
                $this->code(500, '登录用户不存在');
            }

            //代祷是否存在
            $interInfo = Intercession::findByIntercessionId($intercession_id);
            if(!$interInfo) {
                $this->code(451, '代祷内容不存在');
            }

            //添加代祷
            $intercessionComments = new IntercessionComments();
            $intercessionComments->add([
                'user_id' => $interInfo['user_id'],
                'intercession_id' => $intercession_id,
                'comment_by_id' => $user_id,
                'praise_number' => 0,
                'ip' => yii::$app->request->getUserIP(),
                'created_at' => time(),
                'updated_at' => time(),
                'content' => $content,
            ]);

            //递增代祷表评论数量
            $intercession = new Intercession();
            $intercession->increaseComments($intercession_id);

            try {
                //推送消息
                if($user_id != $interInfo['user_id']) {
                    yii::$app->jPush->push()
                        ->setPlatform('all')
                        ->addAlias($interInfo['user_id'] . '')
                        ->addAndroidNotification($userInfo['nickname'] . '祝福了你的代祷事项。', null, 1, ['message_id' => $intercession_id, 'message_type' => 3])
                        ->addIosNotification($userInfo['nickname'] . '祝福了你的代祷事项。', null, null, null, null, ['message_id' => $intercession_id, 'message_type' => 3])
                        ->send();
                }
            }catch (\Exception $e) {
                if(1011 != $e->getCode()) {
                    $trans->rollBack();
                    $this->code(500, $e->getMessage());
                }
            }

            //返回
            $trans->commit();
            $this->code(200);
        }catch (Exception $e) {
            $trans->rollBack();
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 代祷详情
     * @param $user_id
     * @param $intercession_id
     */
    public function actionIntercessionDetail($user_id, $intercession_id)
    {
        try {
            //获取代祷信息
            $intercessionInfo = Intercession::findByIntercessionId($intercession_id);
            if(!$intercessionInfo) {
                $this->code(450, '未找到代祷信息');
            }

            //获取最新头像
            $portraitInfo = Portrait::findByUserId($intercessionInfo['user_id']);

            //获取代祷更新列表
            $updateList = IntercessionUpdate::getListWithIntercessionId($intercession_id);
            $resultUpdateList = [];
            foreach($updateList as $updateInfo) {
                $resultUpdateList[] = [
                    'content' => $updateInfo['content'],
                    'create_time' => $updateInfo['created_at'] * 1000,
                ];
            }
            $resultUpdateList = array_merge($resultUpdateList, [[
                'content' => $intercessionInfo['content'],
                'create_time' => $intercessionInfo['created_at'] * 1000,
            ]]);

            //获取代祷勇士
            $intercessorsList = IntercessionJoin::getAllByIntercessionId($intercession_id);
            $resultIntercessorsList = [];
            foreach($intercessorsList as $intercessorsInfo) {
                $resultIntercessorsList[] = [
                    'user_id' => $intercessorsInfo['id'],
                    'nick_name' => $intercessorsInfo['nickname'],
                ];
            }

            //是否已经加入代祷
            $intercessionJoinInfo = IntercessionJoin::findByIntercessionIdAndIntercessorsId($intercession_id, $user_id);

            //获取代祷发布人的昵称
            $userInfo = User::findIdentity($intercessionInfo['user_id']);

            //构造返回数据
            $data = [
                'intercession_id' => $intercession_id,
                'content_list' => $resultUpdateList,
                'intercession_number' => $intercessionInfo['intercessions'],
                'avatar' => !$portraitInfo ? '' : yii::$app->qiniu->getDomain() . '/' .$portraitInfo['portrait_name'],
                'time' => $intercessionInfo['created_at'] * 1000,
                'position' => $intercessionInfo['position'],
                'intercessors_list' => $resultIntercessorsList,
                'is_interceded' => $intercessionJoinInfo ? true : false,
                'nick_name' => $userInfo['nickname'],
                'user_id' => $intercessionInfo['user_id'],
                'gender' => $userInfo['gender'],
            ];
            $this->code(200, 'ok', $data);

        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 检测新版本
     * @param $version
     * @param $platform
     */
    public function actionVersion($version, $platform)
    {
        try {
            if($platform == 'android') {
                $versionInfo = AppVersion::findLatestVersion(1);
            }else {
                $versionInfo = AppVersion::findLatestVersion(2);
            }
            if(!$versionInfo) {
                echo json_encode([], JSON_FORCE_OBJECT);exit;
            }
            $latestVersion = $versionInfo['version'];
            $latestVersionNumber = str_replace('.', '', $latestVersion);
            $versionNumber = str_replace('.', '', $version);
            if($latestVersionNumber <= $versionNumber) {
                echo json_encode([], JSON_FORCE_OBJECT);exit;
            }
            $this->code(200, 'ok', [
                'latest_version' => $latestVersion,
                'description' => $versionInfo['description'],
                'updated_at' => $versionInfo['created_at'] * 1000,
                'download_url' => 'http://fir.im/huoshi',
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 每日一问
     */
    public function actionAskedDaily()
    {
        try {
            $askInfo = AskedDaily::findLasted();
            if(!$askInfo) {
                $this->code(450, '未找到每日一问内容');
            }
            $this->code(200, 'ok', [
                'question_id' => $askInfo['id'],
                'title' => $askInfo['title'],
                'content' => str_replace("\n", "\n\n", $askInfo['content']),
                'url' => $askInfo['url'],
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 每日一问详情
     */
    public function actionAskedDailyDetail($share_id)
    {
        try {
            $askInfo = AskedDaily::findById($share_id);
            if(!$askInfo) {
                $this->code(450, '未找到每日一问内容');
            }
            $this->code(200, 'ok', [
                'question_id' => $askInfo['id'],
                'title' => $askInfo['title'],
                'content' => str_replace("\n", "\n\n", $askInfo['content']),
                'url' => $askInfo['url'],
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 背诵统计记录
     * @param $user_id integer 用户id
     * @param $topic string 背诵主题
     * @param $minutes integer 本次背诵消耗时间/分钟
     * @param $chapter_no integer 背诵章节数
     * @param $word_no integer 本次背诵字数
     * @param $rate_of_progress integer 当前背诵进度
     * @throws \Exception
     */
    public function actionReciteRecord($user_id, $topic, $minutes, $chapter_no, $word_no, $rate_of_progress, $topic_id)
    {
        try {
            $reciteRecord = new ReciteRecord();
            $is = $reciteRecord->add([
                'user_id' => $user_id,
                'topic' => $topic,
                'minutes' => $minutes,
                'chapter_no' => $chapter_no,
                'word_no' => $word_no,
                'rate_of_progress' => $rate_of_progress,
                'recite_date' => date('Ymd'),
                'created_at' => time(),
                'topic_id' => $topic_id,
            ]);
            if(!$is) {
                throw new \Exception(json_encode($reciteRecord->getErrors()));
            }
            $this->code(200, '', []);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 获取打卡天数及上次背诵信息
     * @param $user_id
     */
    public function actionReciteInfo($user_id)
    {
        try {
            $reciteRecord = new ReciteRecord();

            //获取打卡天数
            $clockDays = $reciteRecord->getClockDays($user_id);

            //获取上次进度
            $topic_id = $rateOfProgress = 0;
            $topic = '';
            $record = $reciteRecord->findLastRecord($user_id);
            if($record) {
                $rateOfProgress = $record['rate_of_progress'];
                $topic = $record['topic'];
                $topic_id = $record['topic_id'];
            }

            $this->code(200, '', [
                'clock_days' => $clockDays,
                'rate_of_progress' => $rateOfProgress,
                'topic' => $topic,
                'topic_id' => $topic_id,
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 关注
     * @param $user_id
     * @param $target_user_id
     */
    public function actionFollow($user_id, $target_user_id)
    {
        try {
            //被关注的人是`target_user_id`
            //应该保存在`user_id`字段
            //因为你关注别人你就是别人的朋友了
            $friends = new Friends();
            $friendInfo = Friends::findByFriendIdAndUserId($target_user_id, $user_id);
            if(!$friendInfo) {
                $is = $friends->add([
                    'user_id' => $user_id,
                    'friend_user_id' => $target_user_id,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
                if(!$is) {
                    throw new Exception(json_encode($friends->getErrors()));
                }
            }
            $this->code(200, '', []);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 获取某用户资料
     * @param $user_id integer 自己的用户id
     * @param $target_user_id integer 被查资料的用户id
     */
    public function actionUserInfo($user_id, $target_user_id)
    {
        try{
            //查看用户信息
            $target_user_info = User::findIdentity($target_user_id);
            if(!$target_user_info) {
                $this->code(450, '该用户不存在');
            }

            //查看头像
            $portrait_info = Portrait::findByUserId($target_user_id);

            //查看用户读经时间
            $read_info = ReadingTime::findByUserId($target_user_id);

            //查看代祷加入次数
            $intercession_join_times = IntercessionJoin::findTotalWithIntercessorsId($target_user_id);

            //返回
            $this->code(200, '', [
                'nickname' => $target_user_info['nickname'],
                'avatar' => !$portrait_info ? '' : yii::$app->qiniu->getDomain() . '/' .$portrait_info['portrait_name'],
                'address' => $target_user_info['province_name'] . ($target_user_info['city_name'] ? '·' . $target_user_info['city_name'] : ''),
                'believe_date' => date('Y/m/d', $target_user_info['believe_date']),
                'total_minutes' => $read_info ? $read_info['total_minutes'] : 0,
                'intercession_join_times' => $intercession_join_times ? $intercession_join_times : 0,
            ]);
        }catch (Exception $e) {
            $this->code(500, $e->getMessage());
        }
    }

    /**
     * 获取我的代祷列表
     * @param $user_id
     * @param $start_page
     * @param $page_no
     * @throws Exception
     * @return array
     */
    protected function myIntercessionList($user_id, $start_page, $page_no)
    {
        $intercessionList = Intercession::findAllByUserId($user_id, $start_page, $page_no);

        //获取最新头像
        $portraitInfo = Portrait::findByUserId($user_id);

        //获取用户信息
        $userInfo = User::findIdentity($user_id);
        if(!$userInfo)
            throw new Exception('用户不存在');

        //分类数据
        $data = [];
        if($intercessionList) {
            foreach($intercessionList as $v) {

                //获取代祷更新列表
                $updateList = IntercessionUpdate::getListWithIntercessionId($v['id']);
                $resultUpdateList = [];
                foreach($updateList as $updateInfo) {
                    $resultUpdateList[] = [
                        'content' => $updateInfo['content'],
                        'create_time' => $updateInfo['created_at'] * 1000,
                    ];
                }
                $resultUpdateList = array_merge($resultUpdateList, [[
                    'content' => $v['content'],
                    'create_time' => $v['created_at'] * 1000,
                ]]);

                //获取代祷勇士
                $intercessorsList = IntercessionJoin::getAllByIntercessionId($v['id']);
                $resultIntercessorsList = [];
                foreach($intercessorsList as $intercessorsInfo) {
                    $resultIntercessorsList[] = [
                        'user_id' => $intercessorsInfo['id'],
                        'nick_name' => $intercessorsInfo['nickname'],
                    ];
                }

                //构造返回数据
                $data[] = [
                    'user_id' => $v['user_id'],
                    'intercession_id' => $v['id'],
                    'content_list' => $resultUpdateList,
                    'intercession_number' => $v['intercessions'],
                    'avatar' => !$portraitInfo ? '' : yii::$app->qiniu->getDomain() . '/' .$portraitInfo['portrait_name'],
                    'nick_name' => $userInfo['nickname'],
                    'time' => $v['created_at'] * 1000,
                    'relationship' => 0,
                    'position' => $v['position'],
                    'intercessors_list' => $resultIntercessorsList,
                    'is_interceded' => true,
                    'gender' => (int)$userInfo['gender'],
                ];
            }
        }
        return $data;
    }

    /**
     * 获取我加入的代祷列表
     * @param $user_id
     * @param $start_page
     * @param $page_no
     * @throws Exception
     * @return array
     */
    protected function myJoinIntercessionList($user_id, $start_page, $page_no)
    {
        //查询三维内的好友id
        $allIdArray = Friends::findFriendsByUserIdAndDepth($user_id, 3);

        //处理数组
        $handleArray = [];
        foreach($allIdArray as $v) {
            $handleArray[$v['depth']][] = $v['friend_user_id'];
        }
        $userIdArray = $newArray = [];
        foreach($handleArray as $k => $handleIdArray) {
            $handleIdArray = array_unique($handleIdArray);
            if(1 != $k) {
                foreach($handleIdArray as $handleKey => $handleValue) {
                    $handleArray[1] = isset($handleArray[1]) ? $handleArray[1] : [];
                    $handleArray[2] = isset($handleArray[2]) ? $handleArray[2] : [];
                    if(2 == $k && (in_array($handleValue, $handleArray[1]) || $handleValue == $user_id)) {
                        unset($handleIdArray[$handleKey]);
                    }
                    if(3 == $k && (in_array($handleValue, $handleArray[1]) || in_array($handleValue, $handleArray[2]) || $handleValue == $user_id)) {
                        unset($handleIdArray[$handleKey]);
                    }
                }
            }
            $newArray[$k] = $handleIdArray;
            $userIdArray = array_merge($userIdArray, $handleIdArray);
        }

        //查询参与的代祷列表
        $intercessionList = IntercessionJoin::findAllByUserId($user_id, $start_page, $page_no);

        //分类数据
        $data = [];
        if($intercessionList) {
            foreach($intercessionList as $v) {

                //代祷人与自己的关系
                $relationship = 0;
                foreach($newArray as $kUser => $vUser) {
                    if($user_id == $v['user_id']) {
                        $relationship = 0;
                    }
                    if(in_array($v['user_id'], $vUser)) {
                        $relationship = $kUser;
                    }
                }

                //获取最新头像
                $portraitInfo = Portrait::findByUserId($v['user_id']);

                //获取代祷更新列表
                $updateList = IntercessionUpdate::getListWithIntercessionId($v['id']);
                $resultUpdateList = [];
                foreach($updateList as $updateInfo) {
                    $resultUpdateList[] = [
                        'content' => $updateInfo['content'],
                        'create_time' => $updateInfo['created_at'] * 1000,
                    ];
                }
                $resultUpdateList = array_merge($resultUpdateList, [[
                    'content' => $v['content'],
                    'create_time' => $v['created_at'] * 1000,
                ]]);

                //获取代祷勇士
                $intercessorsList = IntercessionJoin::getAllByIntercessionId($v['id']);
                $resultIntercessorsList = [];
                foreach($intercessorsList as $intercessorsInfo) {
                    $resultIntercessorsList[] = [
                        'user_id' => $intercessorsInfo['id'],
                        'nick_name' => $intercessorsInfo['nickname'],
                    ];
                }

                //构造返回数据
                $data[] = [
                    'user_id' => $v['user_id'],
                    'intercession_id' => $v['id'],
                    'content_list' => $resultUpdateList,
                    'intercession_number' => $v['intercessions'],
                    'avatar' => !$portraitInfo ? '' : yii::$app->qiniu->getDomain() . '/' .$portraitInfo['portrait_name'],
                    'nick_name' => $v['nickname'],
                    'time' => $v['created_at'] * 1000,
                    'relationship' => $relationship,
                    'position' => $v['position'],
                    'intercessors_list' => $resultIntercessorsList,
                    'is_interceded' => true,
                    'gender' => (int)$v['gender'],
                ];
            }
        }
        return $data;
    }

    protected function code($status = 200, $message = '', $data = [])
    {
        $response = yii::$app->getResponse();
        $response->setStatusCode($status);
        $data = $this->format($data);
        if(200 == $status) {
            $response->data = $data;
        }else {
            $response->content = $message;
        }
        $this->log($response);
        yii::$app->end(0, $response);
    }

    protected function log($response)
    {
        $log = new ApiLog();
        $log->add([
            'route' => $this->route,
            'request_type' => yii::$app->request->method,
            'url' => yii::$app->request->absoluteUrl,
            'params' => urldecode(http_build_query($_REQUEST)),
            'status' => $response->statusCode,
            'response' => json_encode($response->data, JSON_UNESCAPED_UNICODE),
            'ip' => yii::$app->request->getUserIP(),
            'created_at' => time(),
            'memory' => (memory_get_usage() - $this->startMemory) / 1000,
            'response_time' => sprintf('%.2f', (microtime(true) - $this->startTime)),
        ]);
    }

    protected function format($data)
    {
        $newData = [];
        foreach($data as $k => $v) {
            if(is_array($v)) {
                $newData[$k] = $this->format($v);
            }elseif($v === null) {
                $newData[$k] = '';
            }else {
                $newData[$k] = $v;
            }
        }
        return $newData;
    }
}
