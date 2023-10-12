<?php


namespace MediaServer\Rtmp;

/**
 * rtmp协议控制信息处理流程
 */
trait RtmpControlHandlerTrait
{
    /**
     * 处理rtmp
     * @return void
     */
    public function rtmpControlHandler()
    {
        /** 获取当前时间戳 */
        $b = microtime(true);
        /** 获取当前的数据包 */
        $p = $this->currentPacket;
        /** 判断类型 */
        switch ($p->type) {
            /** 设置数据分包大小 */
            case RtmpPacket::TYPE_SET_CHUNK_SIZE:
                /** 解码 */
                list(, $this->inChunkSize) = unpack("N", $p->payload);
                logger()->debug('set inChunkSize ' . $this->inChunkSize);
                break;
                /** 终止 */
            case RtmpPacket::TYPE_ABORT:
                break;
                /** 确认 */
            case RtmpPacket::TYPE_ACKNOWLEDGEMENT:
                break;
                /** ack大小 */
            case RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE:
                list(, $this->ackSize) = unpack("N", $p->payload);
                logger()->debug('set ack Size ' . $this->ackSize);
                break;
                /** 同行宽带 */
            case RtmpPacket::TYPE_SET_PEER_BANDWIDTH:
                break;
        }

        //logger()->info("rtmpControlHandler use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }
}