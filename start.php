<?php
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\TcpConnection;
require_once __DIR__ . '/vendor/autoload.php';

// 初始化一个worker容器，监听1234端口
$worker = new Worker('websocket://0.0.0.0:5678');
// 心跳间隔55秒
define('HEARTBEAT_TIME', 5);
/*
 * 注意这里进程数必须设置为1
 */
$worker->count = 1;
// worker进程启动后创建一个text Worker以便打开一个内部通讯端口
$worker->onWorkerStart = function($worker)
{
    // 开启一个内部端口，方便内部系统推送数据，Text协议格式 文本+换行符
    $inner_text_worker = new Worker('text://0.0.0.0:5677');
    $inner_text_worker->onMessage = function(TcpConnection $connection, $buffer)
    {
        // $data数组格式，里面有uid，表示向那个uid的页面推送数据
        $data = json_decode($buffer, true);
        $uid = $data['data']['uid'];
        // 通过workerman，向uid的页面推送数据
        $ret = sendMessageByUid($uid, $buffer);
        // 返回推送结果
        $connection->send($ret ? 'ok' : 'fail');
    };
    // ## 执行监听 ##
    $inner_text_worker->listen();

    Timer::add(10, function()use($worker){
        $time_now = time();
        foreach($worker->connections as $connection) {
            // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $time_now;
                continue;
            }
            // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
            if ($time_now - $connection->lastMessageTime > HEARTBEAT_TIME) {
                $connection->close();
            }
        }
    });

};
// 新增加一个属性，用来保存uid到connection的映射
$worker->uidConnections = array();
// 当有客户端发来消息时执行的回调函数
$worker->onMessage = function(TcpConnection $connection, $data)
{
    // 给connection临时设置一个lastMessageTime属性，用来记录上次收到消息的时间
    $connection->lastMessageTime = time();
    $data = json_decode($data);
    global $worker;

    // 判断当前客户端是否已经验证,既是否设置了uid
    if(!isset($connection->uid) && $data->type == 'init')
    {
       // 没验证的话把第一个包当做uid（这里为了方便演示，没做真正的验证）
       $connection->uid = $data->uid;
       /* 保存uid到connection的映射，这样可以方便的通过uid查找connection，
        * 实现针对特定uid推送数据
        */
       $worker->uidConnections[$connection->uid] = $connection;
       broadcast(json_encode(['type'=>'broadcast','total_people'=>'在线总人数:'.count($worker->uidConnections)."人",'online_user'=>$data->uid.'上线了']));
       return;
    }
    $type = $data->type;
    switch($type){
        case "heartbeat":
            $connection->send(json_encode(['type'=>'heartbeat','content'=>'server is running !']));
            break;    
        case "chat":
            // $connection->send(json_encode(['type'=>'chat','content'=>$data->content]));
            broadcast(json_encode(['type'=>'chat','data'=>['content'=>$data->content,'uid'=>$data->uid]]));
            break;
        default:

    }
    
};

// 当有客户端连接断开时
$worker->onClose = function(TcpConnection $connection)
{
    global $worker;
    if(isset($connection->uid))
    {
        // 连接断开时删除映射
        unset($worker->uidConnections[$connection->uid]);
    }
};

// 向所有验证的用户推送数据
function broadcast($message)
{
   global $worker;
   foreach($worker->uidConnections as $connection)
   {
        $connection->send($message);
   }
//    print_r($worker->uidConnections);
}

// 针对uid推送数据
function sendMessageByUid($uid, $message)
{
    global $worker;
    if(isset($worker->uidConnections[$uid]))
    {
        $connection = $worker->uidConnections[$uid];
        print_r($connection);
        $connection->send($message);
        return true;
    }
    return false;
}

// 运行所有的worker
Worker::runAll();