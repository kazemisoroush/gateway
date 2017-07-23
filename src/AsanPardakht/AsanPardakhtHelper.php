<?php

namespace Larabookir\Gateway\AsanPardakht;

trait AsanPardakhtHelper {

    /**
     * The main encrypt method for asan pardakht.
     *
     * @param string $string
     *
     * @return string
     */
    private function encrypt($string = "")
    {
        $key = base64_decode($this->config->get('gateway.asan-pardakht.key'));
        $iv = base64_decode($this->config->get('gateway.asan-pardakht.iv'));

        return base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key, $this->addPadding($string), MCRYPT_MODE_CBC, $iv));
    }

    /**
     * @param string  $string
     * @param integer $blockSize
     *
     * @return string
     */
    private function addPadding($string, $blockSize = 32)
    {
        $length = strlen($string);
        $padding = $blockSize - ($length % $blockSize);
        $string .= str_repeat(chr($padding), $padding);

        return $string;
    }

    /**
     * @param $string
     *
     * @return boolean | string
     */
    private function stripPadding($string)
    {
        $slast = ord(substr($string, - 1));
        $slastc = chr($slast);
        $pcheck = substr($string, - $slast);

        if(preg_match("/$slastc{" . $slast . "}/", $string)) {
            $string = substr($string, 0, strlen($string) - $slast);

            return $string;
        } else {
            return false;
        }
    }

    /**
     * @param string $string
     *
     * @return boolean | string
     */
    private function decrypt($string = "")
    {
        $key = base64_decode($this->config->get('gateway.asan-pardakht.key'));
        $iv = base64_decode($this->config->get('gateway.asan-pardakht.iv'));
        $string = base64_decode($string);

        return $this->stripPadding(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, $string, MCRYPT_MODE_CBC, $iv));
    }

}