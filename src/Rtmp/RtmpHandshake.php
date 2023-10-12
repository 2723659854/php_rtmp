<?php


namespace MediaServer\Rtmp;

/**
 * 服务端生成握手的s0s1s2的方法
 */
class RtmpHandshake
{

    const RTMP_HANDSHAKE_UNINIT = 0;
    const RTMP_HANDSHAKE_C0 = 1;
    const RTMP_HANDSHAKE_C1 = 2;
    const RTMP_HANDSHAKE_C2 = 3;


    /**
     * 服务端生成s0 s1 s2
     * @param $c1
     * @return false|string
     * @note s0 固定为0x03
     * @note s1 | 4字节time | 4字节模式串 | 前半部分764字节 | 4字节offset | left[...] | 32字节digest | right[...] |
     * @note 语法，3，s1,s2
     */
    static function handshakeGenerateS0S1S2($c1)
    {
        $data = pack("Ca1536a1536",
            3,
            self::handshakeGenerateS1(),
            self::handshakeGenerateS2($c1)
        );
        return $data;
    }

    /**
     * s1生成
     * @return false|string
     * @note 时间戳，0，1528个随机字符
     */
    static function handshakeGenerateS1()
    {
        $s1 = pack('NNa1528',
            timestamp(),
            0,
            make_random_str(1528)
        );
        return $s1;
    }

    /**
     * 生成s2
     * @param $c1
     * @return false|string
     * @note 客户端时间戳，本地毫秒时间戳，客户端时间戳
     */
    static function handshakeGenerateS2($c1)
    {
        $c1Data = unpack('Ntimestamp/Nzero/a1528random', $c1);
        $s2 = pack('NNa1528',
            $c1Data['timestamp'],
            timestamp(),
            $c1Data['random']
        );
        return $s2;
    }

}
