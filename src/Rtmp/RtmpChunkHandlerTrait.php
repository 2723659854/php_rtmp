<?php


namespace MediaServer\Rtmp;

use \Exception;
use MediaServer\Utils\BinaryStream;

/**
 * 分包
 * Trait RtmpChunkHandlerTrait
 * @package MediaServer\Rtmp
 */
trait RtmpChunkHandlerTrait
{

    /**
     * 数据分包
     * @note 记录一下，websocket协议传输数据用掩码，是为了防止链路层抓包数据。链路层完成从 IP 地址到 MAC 地址的转换。ARP 请求以广播形式发送，网络上的主机可以自主发送 ARP 应答消息，
     * @note 网络层级：https://blog.csdn.net/qq_31347869/article/details/107433744
     * @note 这里rtmp也用了掩码
     */
    public function onChunkData()
    {
        /**
         * 首先获取二进制流
         * @var $stream BinaryStream
         */
        $stream = $this->buffer;
        /** 判断分包状态 */
        switch ($this->chunkState) {
            /** 开始分包 */
            case RtmpChunk::CHUNK_STATE_BEGIN:
                /** 读取第一位 */
                if ($stream->has(1)) {
                    /** 标记一下位置 */
                    $stream->tag();
                    /** 读取一个字节转化为无符号数据  */
                    $header = $stream->readTinyInt();
                    /** 回滚到上面标记的位置，意思就是读取了头部数据后，还原数据的指针 */
                    $stream->rollBack();
                    /** 读取头部的长度 */
                    $chunkHeaderLen = RtmpChunk::BASE_HEADER_SIZES[$header & 0x3f] ?? 1; //base header size
                    //logger()->info('base header size ' . $chunkHeaderLen);
                    /** 读取消息长度 */
                    $chunkHeaderLen += RtmpChunk::MSG_HEADER_SIZES[$header >> 6]; //messaege header size
                    //logger()->info('base + msg header size ' . $chunkHeaderLen);
                    /** 包长度 */
                    //base header + message header
                    $this->chunkHeaderLen = $chunkHeaderLen;
                    /** 修改分包状态为准备完毕 */
                    $this->chunkState = RtmpChunk::CHUNK_STATE_HEADER_READY;

                } else {
                    break;
                }
                /** 数据分包准备完毕状态 */
            case RtmpChunk::CHUNK_STATE_HEADER_READY:
                /** 判断是否有指定长度的数据 */
                if ($stream->has($this->chunkHeaderLen)) {
                    /** 读取头部 */
                    //get base header + message header
                    $header = $stream->readTinyInt();
                    /** 获取格式 */
                    $fmt = $header >> 6;
                    /** 数据的id 为什么数据传输都要用& | >> 运算呢，是减小包体积，还是为了加密 */
                    /** 通过头部确定对方是大端存储还是小端存储 ，数据解码从前往后，还是从后往前 */
                    switch ($csId = $header & 0x3f) {
                        /** 大端存储 */
                        case 0:
                            $csId = $stream->readTinyInt() + 64;
                            break;
                        case 1:
                            //小端
                            /** 小端存储 */
                            $csId = 64 + $stream->readInt16LE();
                            break;
                    }

                    //logger()->info("header ready fmt {$fmt}  csid {$csId}");
                    //找出当前的流所属的包
                    /** 如果没有当前流所属的包 */
                    if (!isset($this->allPackets[$csId])) {
                        logger()->info("new packet csid {$csId}");
                        /** 实例化rtmp数据包 */
                        $p = new RtmpPacket();
                        /** 数据流分包id */
                        $p->chunkStreamId = $csId;
                        /** 数据包长度 */
                        $p->baseHeaderLen = RtmpChunk::BASE_HEADER_SIZES[$csId] ?? 1;
                        /** 保存数据包 */
                        $this->allPackets[$csId] = $p;
                    } else {
                        //logger()->info("old packet csid {$csId}");
                        $p = $this->allPackets[$csId];
                    }

                    /** 设置编码格式 */
                    //set fmt
                    $p->chunkType = $fmt;
                    //更新长度数据
                    $p->chunkHeaderLen = $this->chunkHeaderLen;

                    //base header 长度不变
                    //$p->baseHeaderLen = RtmpPacket::$BASEHEADERSIZE[$csId] ?? 1;
                    /** 计算头部长度，应该是去掉头部前面符号 ，比如ws协议前面有W等字符 */
                    $p->msgHeaderLen = $p->chunkHeaderLen - $p->baseHeaderLen;

                    //logger()->info("packet chunkheaderLen  {$p->chunkHeaderLen}  msg header len {$p->msgHeaderLen}");
                    //当前包
                    $this->currentPacket = $p;
                    /** 更新状态为分包完成 */
                    $this->chunkState = RtmpChunk::CHUNK_STATE_CHUNK_READY;
                    /** 如果是微信数据包 */
                    if ($p->chunkType === RtmpChunk::CHUNK_TYPE_3) {
                        //直接进入判断是否需要读取扩展时间戳的流程
                        $p->state = RtmpPacket::PACKET_STATE_EXT_TIMESTAMP;
                    } else {
                        //当前包的状态初始化
                        $p->state = RtmpPacket::PACKET_STATE_MSG_HEADER;

                    }
                } else {
                    break;
                }
            case RtmpChunk::CHUNK_STATE_CHUNK_READY:
                if (false === $this->onPacketHandler()) {
                    break;
                }
            default:
                //跑一下看看剩余的数据够不够
                $this->onChunkData();
                break;
        }


    }


    /**
     * @param $packet
     * @return string
     */
    public function rtmpChunksCreate(&$packet)
    {
        $baseHeader = $this->rtmpChunkBasicHeaderCreate($packet->chunkType, $packet->chunkStreamId);
        $baseHeader3 = $this->rtmpChunkBasicHeaderCreate(RtmpChunk::CHUNK_TYPE_3, $packet->chunkStreamId);

        $msgHeader = $this->rtmpChunkMessageHeaderCreate($packet);

        $useExtendedTimestamp = $packet->timestamp >= RtmpPacket::MAX_TIMESTAMP;

        $timestampBin = pack('N', $packet->timestamp);
        $out = $baseHeader . $msgHeader;
        if ($useExtendedTimestamp) {
            $out .= $timestampBin;
        }

        //读取payload
        $readOffset = 0;
        $chunkSize = $this->outChunkSize;
        while ($remain = $packet->length - $readOffset) {

            $size = min($remain, $chunkSize);
            //logger()->debug("rtmpChunksCreate remain {$remain} size {$size}");
            $out .= substr($packet->payload, $readOffset, $size);
            $readOffset += $size;
            if ($readOffset < $packet->length) {
                //payload 还没读取完
                $out .= $baseHeader3;
                if ($useExtendedTimestamp) {
                    $out .= $timestampBin;
                }
            }

        }

        return $out;
    }


    /**
     * @param $fmt
     * @param $cid
     */
    public function rtmpChunkBasicHeaderCreate($fmt, $cid)
    {
        if ($cid >= 64 + 255) {
            //cid 小端字节序
            return pack('CS', $fmt << 6 | 1, $cid - 64);
        } elseif ($cid >= 64) {
            return pack('CC', $fmt << 6 | 0, $cid - 64);
        } else {
            return pack('C', $fmt << 6 | $cid);
        }
    }


    /**
     * @param $packet RtmpPacket
     */
    public function rtmpChunkMessageHeaderCreate($packet)
    {
        $out = "";
        if ($packet->chunkType <= RtmpChunk::CHUNK_TYPE_2) {
            //timestamp
            $out .= substr(pack('N', $packet->timestamp >= RtmpPacket::MAX_TIMESTAMP ? RtmpPacket::MAX_TIMESTAMP : $packet->timestamp), 1, 3);
        }

        if ($packet->chunkType <= RtmpChunk::CHUNK_TYPE_1) {
            //payload len and stream type
            $out .= substr(pack('N', $packet->length), 1, 3);
            //stream type
            $out .= pack('C', $packet->type);
        }

        if ($packet->chunkType == RtmpChunk::CHUNK_TYPE_0) {
            //stream id  小端字节序
            $out .= pack('L', $packet->streamId);
        }

        //logger()->debug("rtmpChunkMessageHeaderCreate " . bin2hex($out));

        return $out;
    }


    public function sendACK($size)
    {
        $buf = hex2bin('02000000000004030000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }

    public function sendWindowACK($size)
    {
        $buf = hex2bin('02000000000004050000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }

    public function setPeerBandwidth($size, $type)
    {
        $buf = hex2bin('0200000000000506000000000000000000');
        $buf = substr_replace($buf, pack('NC', $size, $type), 12);
        $this->write($buf);

    }

    public function setChunkSize($size)
    {
        $buf = hex2bin('02000000000004010000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }


}
