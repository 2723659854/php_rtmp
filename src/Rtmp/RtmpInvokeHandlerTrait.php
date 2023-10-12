<?php


namespace MediaServer\Rtmp;


use MediaServer\MediaServer;
use \Exception;
use React\Promise\PromiseInterface;
use Workerman\Timer;

/**
 * rtmp命令解析
 */
trait RtmpInvokeHandlerTrait
{

    /**
     * @return mixed | void
     * @throws Exception
     */
    public function rtmpInvokeHandler()
    {
        /** 获取当前毫秒时间戳  */
        $b = microtime(true);
        /** 获取当前的包 */
        $p = $this->currentPacket;
        //AMF0 数据解释
        /** 读取消息 */
        $invokeMessage = RtmpAMF::rtmpCMDAmf0Reader($p->payload);
        /** 判断命令 */
        switch ($invokeMessage['cmd']) {
            /** 连接事件 */
            case 'connect':
                $this->onConnect($invokeMessage);
                break;
                /** 释放流事件 */
            case 'releaseStream':
                break;
                /** 发布流媒体信息 ，将本地的流媒体数据推送到远程的服务器，这里本身就是服务器，所以直接不管了 */
            case 'FCPublish':
                break;
                /** 创建流 */
            case 'createStream':
                $this->onCreateStream($invokeMessage);
                break;
                /** 发布流媒体  */
            case 'publish':
                $this->onPublish($invokeMessage);
                break;
                /** 播放 */
            case 'play':
                $this->onPlay($invokeMessage);
                break;
                /** 暂停 */
            case 'pause':
                $this->onPause($invokeMessage);
                break;
                /** 不发布 */
            case 'FCUnpublish':
                break;
                /** 删除流媒体资源 */
            case 'deleteStream':
                $this->onDeleteStream($invokeMessage);
                break;
                /** 关闭资源 */
            case 'closeStream':
                $this->onCloseStream();
                break;
                /** 接收到音频数据 */
            case 'receiveAudio':
                $this->onReceiveAudio($invokeMessage);
                break;
                /** 接收到视频数据 */
            case 'receiveVideo':
                $this->onReceiveVideo($invokeMessage);
                break;
        }
        logger()->info("rtmpInvokeHandler {$invokeMessage['cmd']} use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }

    /**
     * 客户端连接事件
     * @param $invokeMessage
     * @throws Exception
     */
    public function onConnect($invokeMessage)
    {
        /** 获取毫秒时间戳 */
        $b = microtime(true);
        /** 整理应用名称  */
        $invokeMessage['cmdObj']['app'] = str_replace('/', '', $invokeMessage['cmdObj']['app']); //fix jwplayer??
        /** 触发preConnect事件 */
        $this->emit('preConnect', [$this->id, $invokeMessage['cmdObj']]);
        if (!$this->isStarting) {
            return;
        }
        /** 获取命令 */
        $this->connectCmdObj = $invokeMessage['cmdObj'];
        /** 获取应用名称 */
        $this->appName = $invokeMessage['cmdObj']['app'];
        /** 获取编码方式 */
        $this->objectEncoding = (isset($invokeMessage['cmdObj']['objectEncoding']) && !is_null($invokeMessage['cmdObj']['objectEncoding'])) ? $invokeMessage['cmdObj']['objectEncoding'] : 0;

        /** 记录连接时间 */
        $this->startTimestamp = timestamp();
        /** 添加一个定时器 */
        $this->pingTimer = Timer::add($this->pingTime,function(){
            /** 心跳检测 */
            $this->sendPingRequest();
        });
        /** 返回ack */
        $this->sendWindowACK(5000000);
        /** 设置宽带 */
        $this->setPeerBandwidth(5000000, 2);
        /** 设置分包大小 */
        $this->setChunkSize($this->outChunkSize);
        /** 回复客户端 */
        $this->responseConnect($invokeMessage['transId']);

        /** 记录心跳检测时间 */
        $this->bitrateCache = [
            'intervalMs' => 1000,
            'last_update' => $this->startTimestamp,
            'bytes' => 0,
        ];

        logger()->info("[rtmp connect] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage['cmdObj']) . " use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }

    /**
     * 创建流媒体事件
     * @param $invokeMessage
     * @throws Exception
     */
    public function onCreateStream($invokeMessage)
    {
        logger()->info("[rtmp create stream] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
        /** 创建资源 */
        $this->respondCreateStream($invokeMessage['transId']);
    }

    /**
     * 推流
     * @param $invokeMessage
     * @param $isPromise bool 是否异步回调
     * @throws Exception
     */
    public function onPublish($invokeMessage, $isPromise = false)
    {
        if (!$isPromise) {
            //发布一个视频
            logger()->info("[rtmp publish] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            if (!is_string($invokeMessage['streamName'])) {
                return;
            }
            /** 获取流媒体信息 */
            $streamInfo = explode('?', $invokeMessage['streamName']);
            /** 流媒体所属位置，*/
            $this->publishStreamPath = '/' . $this->appName . '/' . $streamInfo[0];
            /** 解析推流参数 */
            parse_str($streamInfo[1] ?? '', $this->publishArgs);
            $this->publishStreamId = $this->currentPacket->streamId;
        }
        //auth check
        /** 权限检查，这里是的权限检查是假的 */
        if (!$isPromise && $result = MediaServer::verifyAuth($this)) {
            if ($result === false) {
                logger()->info("[rtmp publish] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
                //check false
                $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.publish.Unauthorized', 'Authorization required.');
                return;
            }
            /** 如果这个权限检查是异步的 */
            if ($result instanceof PromiseInterface) {
                //异步检查
                $result->then(function () use ($invokeMessage) {
                    //resolve
                    /** 检查通过后，重新推流 */
                    $this->onPublish($invokeMessage, true);
                }, function ($exception) use ($invokeMessage) {
                    logger()->info("[rtmp publish] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage) . " " . $exception->getMessage());
                    //check false
                    $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.publish.Unauthorized', 'Authorization required.');
                });
                return;
            }
        }
        /** 如果已分配推流路径 ，就是每一个资源在服务端都必须有一个空间，临时存放数据，说明这个已经在推流了，这个资源点不能用了 */
        if (MediaServer::hasPublishStream($this->publishStreamPath)) {
            //publishStream already
            logger()->info("[rtmp publish] Already has a stream. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            $this->reject();
            $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.Publish.BadName', 'Stream already publishing');
        } else if ($this->isPublishing) {
            /** 如果正在推流中 */
            logger()->info("[rtmp publish] NetConnection is publishing. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.Publish.BadConnection', 'Connection already publishing');
        } else {
            /** 添加推流 */
            MediaServer::addPublish($this);
            /** 标记为正在推理 */
            $this->isPublishing = true;
            $this->sendStatusMessage($this->publishStreamId, 'status', 'NetStream.Publish.Start', "{$this->publishStreamPath} is now published.");

            //emit on on_publish_ready
            /** 触发事件推流已就绪 */
            $this->emit('on_publish_ready');

        }
    }

    /**
     * 播放事件
     * @param $invokeMessage
     * @param $isPromise bool
     * @throws Exception
     */
    public function onPlay($invokeMessage, $isPromise = false)
    {
        if (!$isPromise) {
            logger()->info("[rtmp play] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            if (!is_string($invokeMessage['streamName'])) {
                return;
            }
            /** @var RtmpPacket $p */
            $parse = explode('?', $invokeMessage['streamName']);
            $this->playStreamPath = '/' . $this->appName . '/' . $parse[0];
            parse_str($parse[1] ?? '', $this->playArgs);
            $this->playStreamId = $this->currentPacket->streamId;
        }

        //auth check
        if (!$isPromise && $result = MediaServer::verifyAuth($this)) {
            if ($result === false) {
                logger()->info("[rtmp play] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
                $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.play.Unauthorized', 'Authorization required.');
                return;
            }

            if ($result instanceof PromiseInterface) {
                //异步检查
                $result->then(function () use ($invokeMessage) {
                    //resolve
                    $this->onPlay($invokeMessage, true);
                }, function ($exception) use ($invokeMessage) {
                    logger()->info("[rtmp play] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage) . " " . $exception->getMessage());
                    //check false
                    $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.play.Unauthorized', 'Authorization required.');
                });
                return;
            }
        }

        if ($this->isPlaying) {
            $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.Play.BadConnection', 'Connection already playing');
        } else {
            /** 返回播放结果 */
            $this->respondPlay();
        }
        /** 添加播放 */
        MediaServer::addPlayer($this);

    }

    /** 暂停 ，这里没有处理 */
    public function onPause($invokeMessage)
    {
        //暂停视频
    }

    /** 删除，没有处理 */
    public function onDeleteStream($invokeMessage)
    {
        //删除流
    }

    /** 关闭资源 */
    public function onCloseStream()
    {
        //关闭流，调用删除流逻辑
        $this->onDeleteStream(['streamId' => $this->currentPacket->streamId]);
    }

    /** 接收到音频数据 */
    public function onReceiveAudio($invokeMessage)
    {
        logger()->info("[rtmp play] receiveAudio=" . ($invokeMessage['bool'] ? 'true' : 'false'));
        /** 标记是否接收到音频数据 */
        $this->isReceiveAudio = $invokeMessage['bool'];
    }

    /** 接收到视频数据 */
    public function onReceiveVideo($invokeMessage)
    {
        logger()->info("[rtmp play] receiveVideo=" . ($invokeMessage['bool'] ? 'true' : 'false'));
        $this->isReceiveVideo = $invokeMessage['bool'];
    }

    /** 发送流媒体状态 */
    public function sendStreamStatus($st, $id)
    {
        /** 16进制转二进制 */
        $buf = hex2bin('020000000000060400000000000000000000');
        $buf = substr_replace($buf, pack('nN', $st, $id), 12);
        $this->write($buf);
    }


    /**
     * 发送invoke消息
     * @param $sid
     * @param $opt
     * @throws Exception
     */
    public function sendInvokeMessage($sid, $opt)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_INVOKE;
        $packet->type = RtmpPacket::TYPE_INVOKE;
        $packet->streamId = $sid;
        $packet->payload = RtmpAMF::rtmpCMDAmf0Creator($opt);
        $packet->length = strlen($packet->payload);
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * 发送数据消息
     *
     * @param $sid
     * @param $opt
     * @throws Exception
     */
    public function sendDataMessage($sid, $opt)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_DATA;
        $packet->type = RtmpPacket::TYPE_DATA;
        $packet->streamId = $sid;
        $packet->payload = RtmpAMF::rtmpDATAAmf0Creator($opt);
        $packet->length = strlen($packet->payload);
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * 发送状态信息
     * @param $sid
     * @param $level
     * @param $code
     * @param $description
     * @throws Exception
     */
    public function sendStatusMessage($sid, $level, $code, $description)
    {
        $opt = [
            'cmd' => 'onStatus',
            'transId' => 0,
            'cmdObj' => null,
            'info' => [
                'level' => $level,
                'code' => $code,
                'description' => $description
            ]
        ];
        $this->sendInvokeMessage($sid, $opt);
    }

    /**
     * 发送权限
     * @param $sid
     * @throws Exception
     */
    public function sendRtmpSampleAccess($sid)
    {
        $opt = [
            'cmd' => '|RtmpSampleAccess',
            'bool1' => false,
            'bool2' => false
        ];
        $this->sendDataMessage($sid, $opt);
    }


    /**
     * 发送心跳消息
     * @throws Exception
     */
    public function sendPingRequest()
    {

        $currentTimestamp = timestamp() - $this->startTimestamp;
        //logger()->debug("send ping time:" . $currentTimestamp);
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_PROTOCOL;
        $packet->type = RtmpPacket::TYPE_EVENT;
        $packet->payload = pack("nN", 6, $currentTimestamp);
        $packet->length = 6;
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }


    /**
     * 返回连接成功数据
     * @param $tid
     * @throws Exception
     */
    public function responseConnect($tid)
    {
        $opt = [
            'cmd' => '_result',
            'transId' => $tid,
            'cmdObj' => [
                'fmsVer' => 'FMS/3,0,1,123',
                'capabilities' => 31
            ],
            'info' => [
                'level' => 'status',
                'code' => 'NetConnection.Connect.Success',
                'description' => 'Connection succeeded.',
                'objectEncoding' => $this->objectEncoding
            ]
        ];
        $this->sendInvokeMessage(0, $opt);
    }

    /**
     * 返回创建资源信息
     * @param $tid
     * @throws Exception
     */
    public function respondCreateStream($tid)
    {
        $this->streams++;
        $opt = [
            'cmd' => '_result',
            'transId' => $tid,
            'cmdObj' => null,
            'info' => $this->streams
        ];
        $this->sendInvokeMessage(0, $opt);
    }

    /**
     * 发送播放数据
     * @throws Exception
     */
    public function respondPlay()
    {
        /** 发送二进制数据 资源已准备 */
        $this->sendStreamStatus(RtmpPacket::STREAM_BEGIN, $this->playStreamId);
        /** 告诉客户端，播放资源重置 */
        $this->sendStatusMessage($this->playStreamId, 'status', 'NetStream.Play.Reset', 'Playing and resetting stream.');
        /** 开始播放 */
        $this->sendStatusMessage($this->playStreamId, 'status', 'NetStream.Play.Start', 'Started playing stream.');
        /** 发送权限 */
        $this->sendRtmpSampleAccess($this->playStreamId);
    }

    /**
     * 拒绝
     * @return void
     */
    public function reject()
    {
        logger()->info("[rtmp reject] reject stream publish id={$this->id}");
        $this->stop();
    }

}
