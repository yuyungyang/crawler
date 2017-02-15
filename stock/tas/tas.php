<?php

date_default_timezone_set("Asia/Taipei") ;
error_reporting(E_ALL) ;
ini_set('display_errors' , true) ;

// lock 
// cli name
// mail notify

try
{
    $strStockId = ! isset($argv[1]) ? ! isset($_GET['id']) ? null : $_GET['id'] : $argv[1]  ;

    if (empty($strStockId))
    {
        throw new Exception('400-01 => Required StockId', 400) ;
    }

    if (time() <= mktime(8, 45, 0))
    {
        error_log('Exclude execute time 00:00 ~ 08:45') ;

        exit(0) ;
    }

    $html = call_user_func(function ($strStockId)
    {
        $strUrl = sprintf('https://tw.stock.yahoo.com/q/ts?s=%s&t=50', $strStockId) ;
        $strReferer = sprintf('https://tw.stock.yahoo.com/q/ts?s=00632R', $strStockId) ;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $strUrl) ;
        curl_setopt($ch, CURLOPT_REFERER, $strReferer) ;
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate, sdch, br') ;
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36') ;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true) ;
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false) ;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false) ;
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30) ;
        curl_setopt($ch, CURLOPT_TIMEOUT, 30) ;
        $strResult = curl_exec($ch) ;
        $arrInfo = curl_getinfo($ch) ;

        if(curl_errno($ch))
        {
            throw new Exception(sprintf('400-02 => HTTP Transport Code:%s, Status:%s', $arrInfo['http_code'], curl_error($ch)), 400) ;
        }
        else if (empty($strResult))
        {
            throw new Exception(sprintf('404-01 => HTTP Transport Code:%s, Empty Result', $arrInfo['http_code']), 404) ;
        }

        curl_close($ch) ;

        return $strResult ;

    }, $strStockId) ;

    $html = mb_convert_encoding($html, 'utf8', 'big5') ;

    $strPrefix = '<table' ;
    $strSuffix = '</table>' ;

    $intFirst = stripos($html, $strPrefix) ;
    $intLast = strripos($html, $strSuffix) ;

    $html = substr($html, $intFirst, ($intLast - $intFirst) + strlen($strSuffix)) ;

    $arrTable = [] ;

    $intCount = substr_count($html, $strPrefix) ;

    if ($intCount != substr_count($html, $strPrefix))
    {
        throw new Exception('400-03 => Prefix Not Found', 400) ;
    }

    for ($intIndex = 1; $intIndex <= $intCount; $intIndex++)
    {
        $intBlockLastPrefix = strripos(rtrim($html, $strPrefix), $strPrefix) ;

        $strChunk = substr($html, $intBlockLastPrefix) ;

        $intBlockFirstSuffix = stripos($strChunk, $strSuffix) ;

        $arrTable[] = substr($strChunk, 0, $intBlockFirstSuffix + strlen($strSuffix)) ;

        $html = substr($html, 0, $intBlockLastPrefix) . substr($strChunk, $intBlockFirstSuffix + strlen($strSuffix)) ;
    }

    unset($html) ;

    $strElement = $arrTable[7] ;

    $strElement = mb_convert_encoding($strElement, 'HTML-ENTITIES', 'utf8') ;

    libxml_use_internal_errors(true) ;

    $objDOMDocument = new DOMDocument() ;

    $objDOMDocument->loadHTML($strElement) ;

    $strFile = sprintf('/home/data/stock/tas/%s/%s/%s.csv', $strStockId, date('Ym'), date('Y-m-d')) ;

    is_dir(dirname($strFile)) || mkdir(dirname($strFile), 0777, true) ;

    $tsEntryPoint = 0 ;

    if (is_file($strFile))
    {
        if ( ! is_writable($strFile))
        {
            throw new Exception(sprintf('403-01 => Permission denied %s', $strFile), 403) ;
        }
        
        $strLatest = `tail -n 1 $strFile` ;

        $arrLatest = explode(',', $strLatest) ;

        $tsEntryPoint = strtotime(date('Y/m/d ').$arrLatest[0]) ;
    }

    $fp = fopen($strFile, 'a') ;

    $objRowItems = $objDOMDocument->getElementsByTagName('tr') ;

    $isNew = true ;

    for ($intRow = ($objRowItems->length - 1); $intRow >= 0; $intRow--)
    {
        $objColumnItems = $objDOMDocument->getElementsByTagName('tr')->item($intRow)->getElementsByTagName('td') ;

        if ( ! preg_match('~..:..:..~', $objColumnItems->item(0)->nodeValue))
        {
            continue ;
        }
        else if ($tsEntryPoint > 0 && $isNew)
        {
            for ($i = 0; $i < 10; $i++)
            {
                $objLastRow = $objDOMDocument->getElementsByTagName('tr')
                                    ->item($i)->getElementsByTagName('td') ;

                if ( ! preg_match('~..:..:..~', $objLastRow->item(0)->nodeValue))
                {
                    continue ;
                }
                else if (strtotime(date('Y/m/d ').$objLastRow->item(0)->nodeValue) <= $tsEntryPoint)
                {
                    break 2 ;
                }
                else
                {
                    $isNew = false ;

                    break ;
                }
            }
        }

        if ($tsEntryPoint > 0 && strtotime(date('Y/m/d ').$objColumnItems->item(0)->nodeValue) <= $tsEntryPoint)
        {
            continue ;
        }

        $arrData = [] ;

        for ($intColumn = 0; $intColumn < $objColumnItems->length; $intColumn++)
        {
            if (in_array($intColumn, [4]))
            {
                continue ;
            }

            $arrData[] = $objColumnItems->item($intColumn)->nodeValue ;
        }

        fputcsv($fp, $arrData) ;
    }

    fclose($fp) ;
    unset($fp) ;
}
catch (Exception $e)
{
    error_log(sprintf('%s at [%s::%s]', $e->getMessage(), $e->getFile(), $e->getLine())) ;

    exit(0) ;
}
