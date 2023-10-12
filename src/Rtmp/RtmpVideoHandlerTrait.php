<?php


namespace MediaServer\Rtmp;

use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\VideoFrame;
use Workerman\Timer;

/**
 * @purpose 视频数据处理
 */
trait RtmpVideoHandlerTrait
{


    public function rtmpVideoHandler()
    {
        //视频包拆解
        /**
         * @var $p RtmpPacket
         */
        $p = $this->currentPacket;
        /** 将视频数据存入视频帧包 */
        $videoFrame = new VideoFrame($p->payload, $p->clock);
        /** 获取视频编码 */
        if ($this->videoCodec == 0) {
            $this->videoCodec = $videoFrame->codecId;
            $this->videoCodecName = $videoFrame->getVideoCodecName();
        }

        /** 获取视频fps 帧率 */
        if ($this->videoFps === 0) {
            //当前帧为第0
            if ($this->videoCount++ === 0) {
                /** 添加一个定时器，统计5秒的fps  */
                $this->videoFpsCountTimer = Timer::add(5,function(){
                    $this->videoFps = ceil($this->videoCount / 5);
                    /** 删除定时器  */
                    $this->videoFpsCountTimer = null;
                },[],false);
            }
        }
        /** 获取视频编码 */
        switch ($videoFrame->codecId) {
            /** 只处理avc格式 */
            case VideoFrame::VIDEO_CODEC_ID_AVC:
                //h264
                /** 获取视频的包信息 */
                $avcPack = $videoFrame->getAVCPacket();
                /** 有视频头部序列就是h264格式 ？？ */
                if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    /** 是否avc序列 */
                    $this->isAVCSequence = true;
                    /** 标记头部为视频帧 */
                    $this->avcSequenceHeaderFrame = $videoFrame;
                    /** 获取包的配置 */
                    $specificConfig = $avcPack->getAVCSequenceParameterSet();
                    /** 视频的宽 */
                    $this->videoWidth = $specificConfig->width;
                    /** 视频的高 */
                    $this->videoHeight = $specificConfig->height;
                    /** 视频资源名称 */
                    $this->videoProfileName = $specificConfig->getAVCProfileName();
                    /** 等级 */
                    $this->videoLevel = $specificConfig->level;
                }
                if ($this->isAVCSequence) {
                    /** 清空连续帧 */
                    if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                        &&
                        $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_NALU) {
                        $this->gopCacheQueue = [];
                    }

                    if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                        &&
                        $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                        //skip avc sequence
                    } else {
                        $this->gopCacheQueue[] = $videoFrame;
                    }
                }

                break;
        }
        //数据处理与数据发送
        $this->emit('on_frame', [$videoFrame, $this]);
        //销毁AVC
        $videoFrame->destroy();

    }
}
