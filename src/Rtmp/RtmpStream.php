<?php


namespace MediaServer\Rtmp;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\DuplexMediaStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Utils\WMBufferStream;
use Workerman\Timer;


/**
 * 流媒体资源
 * Class RtmpStream
 * @package MediaServer\Rtmp
 */
class RtmpStream extends EventEmitter implements DuplexMediaStreamInterface, VerifyAuthStreamInterface
{

    use RtmpHandshakeTrait,/** 握手 */
        RtmpChunkHandlerTrait,/** 分包 */
        RtmpPacketTrait,/** 打包 */
        RtmpTrait,/** */
        RtmpPublisherTrait,/** 推流 */
        RtmpPlayerTrait;/** 播放 */

    /**
     * 握手状态
     * @var int handshake state
     */
    public int $handshakeState;

    public $id;

    public $ip;

    public $port;

    /** 分包头部长度 */
    protected int $chunkHeaderLen = 0;
    /** 分片状态 */
    protected int $chunkState;

    /**
     * 所有的包
     * @var RtmpPacket[]
     */
    protected $allPackets = [];

    /**
     * @var int 接收数据时的  chunk size
     */
    protected    $inChunkSize = 128;
    /**
     * @var int 发送数据时的 chunk size
     */
    protected $outChunkSize = 60000;


    /**
     * 当前的包
     * @var RtmpPacket
     */
    protected $currentPacket;


    public $startTimestamp;

    public $objectEncoding;

    public $streams = 0;

    public $playStreamId = 0;
    public $playStreamPath = '';
    public $playArgs = [];

    public $isStarting = false;

    public $connectCmdObj = null;

    public $appName = '';

    public $isReceiveAudio = true;
    public $isReceiveVideo = true;


    /**
     * @var int
     */
    public $pingTimer;

    /**
     * @var int ping interval
     */
    public $pingTime = 60;
    public $bitrateCache;


    public $publishStreamPath;
    public $publishArgs;
    public $publishStreamId;


    /**
     * @var int 发送ack的长度
     */
    protected $ackSize = 0;

    /**
     * @var int 当前size统计
     */
    protected $inAckSize = 0;
    /**
     * @var int 上次ack的size
     */
    protected $inLastAck = 0;

    public $isMetaData = false;
    /**
     * @var MetaDataFrame
     */
    public $metaDataFrame;


    public $videoWidth = 0;
    public $videoHeight = 0;
    public $videoFps = 0;
    public $videoCount = 0;
    public $videoFpsCountTimer;
    public $videoProfileName = '';
    public $videoLevel = 0;

    public $videoCodec = 0;
    public $videoCodecName = '';
    public $isAVCSequence = false;
    /**
     * @var VideoFrame
     */
    public $avcSequenceHeaderFrame;

    public $audioCodec = 0;
    public $audioCodecName = '';
    public $audioSamplerate = 0;
    public $audioChannels = 1;
    public $isAACSequence = false;
    /**
     * @var AudioFrame
     */
    public $aacSequenceHeaderFrame;
    public $audioProfileName = '';

    public $isPublishing = false;
    public $isPlaying = false;

    public $enableGop = true;

    /**
     * @var MediaFrame[]
     */
    public $gopCacheQueue = [];


    /**
     * @var WMBufferStream
     */
    protected $buffer;

    /**
     * 初始化流媒体
     * PlayerStream constructor.
     * @param $bufferStream WMBufferStream 媒体资源 是tcp协议也是事件
     */
    public function __construct(WMBufferStream $bufferStream)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        /** 先标记为握手还未初始化 */
        $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_UNINIT;
        /** ip */
        $this->ip = '';
        /** 开启了啊 */
        $this->isStarting = true;
        /** 存媒体数据 */
        $this->buffer = $bufferStream;
        /** 绑定接收到数据的事件  */
        $bufferStream->on('onData',[$this,'onStreamData']);
        /** 绑定错误事件 */
        $bufferStream->on('onError',[$this,'onStreamError']);
        /** 绑定关闭事件 */
        $bufferStream->on('onClose',[$this,'onStreamClose']);

        /*
         *  统计数据量代码
         *
         */
        /** 添加一个定时器 5秒后执行 */
         $this->dataCountTimer = Timer::add(5,function(){
             /** 平均事件 */
            $avgTime=$this->frameTimeCount/($this->frameCount?:1);
            /** 统计5秒传输的帧个数 */
            $avgPack=$this->frameCount/5;
            /** 时间 */
            $packPs=(1/($avgTime?:1));
            /** 时间/平均包数 */
            $s=$packPs/$avgPack;
            /** 帧数初始化 */
            $this->frameCount=0;
            /** 时间初始化 */
            $this->frameTimeCount=0;
            /** 已读字节数 */
            $this->bytesRead = $this->buffer->connection->bytesRead;
            /** 比特率 = 已读数据/耗时 */
            $this->bytesReadRate = $this->bytesRead/ (timestamp() - $this->startTimestamp) * 1000;
            //logger()->info("[rtmp on data] {$packPs} pps {$avgPack} ps {$s} stream");
        });
    }

    public $dataCountTimer;
    public $frameCount = 0;
    public $frameTimeCount = 0;
    public $bytesRead = 0;
    public $bytesReadRate = 0;

    /**
     * 接收到数据
     * @return void
     */
    public function onStreamData()
    {
        //若干秒后没有收到数据断开
        $b = microtime(true);

        /** 如果握手没有完成 ，则执行握手 */
        if ($this->handshakeState < RtmpHandshake::RTMP_HANDSHAKE_C2) {
            $this->onHandShake();
        }

        /** 如果已经握手成功 */
        if ($this->handshakeState === RtmpHandshake::RTMP_HANDSHAKE_C2) {
            /** 数据分片 */
            $this->onChunkData();
            /** 计算当前已读数据长度  */
            $this->inAckSize += strlen($this->buffer->recvSize());
            /** 如果长度大于15 */
            if ($this->inAckSize >= 0xf0000000) {
                $this->inAckSize = 0;
                $this->inLastAck = 0;
            }
            /** 长度大于ack */
            if ($this->ackSize > 0 && $this->inAckSize - $this->inLastAck >= $this->ackSize) {
                //每次收到的数据超过ack设的值
                $this->inLastAck = $this->inAckSize;
                /** 发送ack */
                $this->sendACK($this->inAckSize);
            }
        }
        /** 累加帧计时 */
        $this->frameTimeCount += microtime(true) - $b;
        /** 累加收到的帧数  */
        $this->frameCount++;


        //logger()->info("[rtmp on data] per sec handler times: ".(1/($end?:1)));
    }


    /** 如果资源关闭 则关闭这个连接 */
    public function onStreamClose()
    {
        $this->stop();
    }


    /** 发生了错误，关闭连接 */
    public function onStreamError()
    {
        $this->stop();
    }

    /** 发送数据 最终是通过tcp发送的 */
    public function write($data)
    {
        return $this->buffer->connection->send($data,true);
    }

/*    public function __destruct()
    {
        logger()->info("[RtmpStream __destruct] id={$this->id}");
    }*/



}
