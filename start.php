<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Lib\Timer;

class Websocket
{
    private $json;   //接收Json数据
    private $uid;   //用户UID

    //心跳间隔55秒
    const HEARTBEAT_TIME = 55;

    /**
     * Websocket服务
     */
    public function start(){
        $now = date('Y-m-d H:i:s');
        $worker = new Worker('websocket://0.0.0.0:5677');
        $worker->count = 1;
        $worker->onWorkerStart = function ($worker)use($now){
            //心跳设置（进程启动后设置一个每秒运行一次的定时器）
            Timer::add(1, function()use($worker){
                $time_now = time();
                foreach($worker->connections as $connection) {
                    // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
                    if (empty($connection->lastMessageTime)) {
                        $connection->lastMessageTime = $time_now;
                        continue;
                    }
                    // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
                    if ($time_now - $connection->lastMessageTime > static::HEARTBEAT_TIME) {
                        $connection->send("超时，自动断开连接！");
                        $connection->close();
                    }
                }
            });

            $inner_text_worker = new Worker('text://0.0.0.0:5678');
            $inner_text_worker->onMessage = function ($connection,$buffer)use($now){
                $data = json_decode($buffer, true);
                //uid存在单播
                if(isset($data['uid'])){
                    $res = $this->sendMessageByUid($data['uid'],$buffer);
                    $connection->send($res?'ok':'fail');
                    echo "{$now} - 用户:[{$data['uid']}]单播推送：{$res}\n";
                }else{ //广播
                    $res = $this->broadcast($buffer);
                    $connection->send($res ? 'ok':'fail');
                    echo "{$now} - 广播推送：{$res}\n";
                }
            };
            $inner_text_worker->listen();
        };

        $worker->onConnect = function ($connection)use($now){
            $connection->send('Websocket连接成功！');
            echo "{$now} - 新连接！\n";
        };

        $worker->uidConnections = [];
        $worker->onMessage = function ($connection, $data)use($now){
            global $worker;

            //给connection临时设置一个lastMessageTime属性，用来记录上次收到消息的时间
            $connection->lastMessageTime = time();

            //获取用户请求数据
            $json = ['status' => 0, 'expire' => 0, 'message' => '参数异常！', 'data' => null];
            try{
                $this->get_jsons($data);
            }catch (\Exception $ex){
                $json['message'] = '请求参数异常！';
                $connection->send(json_encode($json));
                return ;
            }

            //主动断开连接
            $close = $this->get_value('close',0);
            if($close == 1){
                $json['message'] = "用户:[{$this->uid}]主动与服务器断开连接！";
                $connection->send(json_encode($json));
                $connection->close();
                echo "{$now} - 用户:[{$this->uid}]主动与服务器断开连接！\n";
                return ;
            }

            //检测用户
            $this->uid = $this->get_value('uid');
            if(!$this->uid > 0){
                $json['message'] = "用户Token已过期！";
                $connection->send(json_encode($json));
                $connection->close();
                echo "{$now} - 用户Token已过期！\n";
                return ;
            }else{
                //绑定用户UID
                if(!isset($connection->uid)){
                    $connection->uid = $this->uid;
                    $worker->uidConnections[$connection->uid] = $connection;
                    //连接统计
                    $count = count($worker->uidConnections);
                    echo "{$now} - 用户:[{$connection->uid}]连接成功！,当前共有{$count}人连接！\n";
                    return ;
                }
            }
        };

        $worker->onClose = function($connection)use($now){
            global $worker;
            echo "{$now} - 与服务器断开链接！\n";
            if(isset($connection->uid)){
                $user_id = $connection->uid;
                unset($worker->uidConnections[$connection->uid]);
                unset($connection->uid);
                //连接统计
                $count = count($worker->uidConnections);
                echo "{$now} - 用户:[{$user_id}]与服务器断开连接！,当前还有{$count}人连接！\n";
            }
        };
        Worker::runAll();
    }

    /**
     * 广播
     * @param $message
     * @return bool
     */
    protected function broadcast($message){
        global $worker;
        if(is_array($worker->uidConnections)){
            foreach ($worker->uidConnections as $connection){
                $connection->send($message);
            }
            return true;
        }else{
            return false;
        }
    }

    /**
     * 单播
     * @param $uid
     * @param $message
     * @return bool
     */
    protected function sendMessageByUid($uid, $message){
        global $worker;
        if(isset($worker->uidConnections[$uid])){
            $connection = $worker->uidConnections[$uid];
            $connection->send($message);
            return true;
        }
        return false;
    }

    /**
     * 获取接口平台提交参数
     * @param $input_json
     */
    protected function get_jsons($input_json) {
        $inputs = json_decode($input_json, true);
        $receive = json_decode($inputs['json'],true);
        if (is_array($receive)) {
            foreach ($receive as $key => $value) {
                $this->json[trim($key)] = $value;
            }
        }
    }

    /**
     * 获取app传递的指定数据、或全部
     * @param string $key
     * @param string $default
     * @return string
     */
    protected function get_value($key = '', $default = '') {
        if ($key == '') {
            return $this->json;
        }
        return isset($this->json[$key]) ? $this->json[$key] : $default;
    }
}

$websocket = new Websocket();
$websocket->start();