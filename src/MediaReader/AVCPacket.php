<?php


namespace MediaServer\MediaReader;



use MediaServer\Utils\BinaryStream;

/**
 * @purpose 视频数据包
 */
class AVCPacket
{
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;



    public $avcPacketType;
    public $compositionTime;
    public $stream;

    /**
     * 视频数据包初始化
     * AVCPacket constructor.
     * @param $stream BinaryStream
     */
    public function __construct($stream)
    {
        $this->stream=$stream;
        /** 视频数据包编码格式 */
        $this->avcPacketType=$stream->readTinyInt();
        /** 获取包创建时间 */
        $this->compositionTime=$stream->readInt24();
    }


    /**
     * @var AACSequenceParameterSet
     */
    protected $avcSequenceParameterSet;

    /**
     * 获取画面帧的参数
     * @return AVCSequenceParameterSet
     */
    public function getAVCSequenceParameterSet(){

        if(!$this->avcSequenceParameterSet){
            $this->avcSequenceParameterSet=new AVCSequenceParameterSet($this->stream->readRaw());
        }
        return $this->avcSequenceParameterSet;
    }
}