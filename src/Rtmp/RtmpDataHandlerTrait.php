<?php


namespace MediaServer\Rtmp;


use MediaServer\MediaReader\MetaDataFrame;
use \Exception;

/**
 * rtmp 数据处理
 */
trait RtmpDataHandlerTrait
{

    /**
     * @throws Exception
     */
    public function rtmpDataHandler()
    {
        /** 获取当前的数据包 */
        $p = $this->currentPacket;
        //AMF0 数据解释
        /** 读取命令 */
        $dataMessage = RtmpAMF::rtmpDataAmf0Reader($p->payload);
        logger()->info("rtmpDataHandler {$dataMessage['cmd']} " . json_encode($dataMessage));
        /** 判断命令 */
        switch ($dataMessage['cmd']) {
            /** 设置数据格式 */
            case '@setDataFrame':
                if (isset($dataMessage['dataObj'])) {
                    /** 音频采样频率 */
                    $this->audioSamplerate = $dataMessage['dataObj']['audiosamplerate'] ?? $this->audioSamplerate;
                    /** 声道信息 单声道还是双声道 */
                    $this->audioChannels = isset($dataMessage['dataObj']['stereo']) ? ($dataMessage['dataObj']['stereo'] ? 2 : 1) : $this->audioChannels;
                    /** 视频宽度 */
                    $this->videoWidth = $dataMessage['dataObj']['width'] ?? $this->videoWidth;
                    /** 视频高度 */
                    $this->videoHeight = $dataMessage['dataObj']['height'] ?? $this->videoHeight;
                    /** 视频帧率 */
                    $this->videoFps = $dataMessage['dataObj']['framerate'] ?? $this->videoFps;
                }
                /** 标记 已设置媒体元素 */
                $this->isMetaData = true;
                /** 解析命令 */
                $metaDataFrame = new MetaDataFrame(RtmpAMF::rtmpDATAAmf0Creator([
                    'cmd' => 'onMetaData',
                    'dataObj' => $dataMessage['dataObj']
                ]));
                /** 保存命令 */
                $this->metaDataFrame = $metaDataFrame;
                /** 设置回调 on_frame事件 */
                $this->emit('on_frame', [$metaDataFrame, $this]);

            //播放类群发onMetaData
        }
    }
}