<?php

date_default_timezone_set("Asia/Taipei") ;
error_reporting(E_ALL) ;
ini_set('display_errors' , true) ;
ini_set('max_execution_time', 600) ;
// set_time_limit(5) ;

try
{
    $html = call_user_func(function ()
    {
        $strUrl = 'http://isin.twse.com.tw/isin/C_public.jsp?strMode=2' ;
        $strReferer = 'http://www.tse.com.tw/ch/products/stock_code2.php' ;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $strUrl) ;
        curl_setopt($ch, CURLOPT_REFERER, $strReferer) ;
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate, sdch') ;
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
            throw new Exception(sprintf('400-01 => HTTP Transport Code:%s, Status:%s', $arrInfo['http_code'], curl_error($ch)), 400) ;
        }
        else if (empty($strResult))
        {
            throw new Exception(sprintf('404-01 => HTTP Transport Code:%s, Empty Result', $arrInfo['http_code']), 404) ;
        }

        curl_close($ch) ;

        return $strResult ;
    }) ;

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
        throw new Exception('400-02 => Prefix Not Found', 400) ;
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

    $strElement = $arrTable[0] ;

    $strElement = mb_convert_encoding($strElement, 'HTML-ENTITIES', 'utf8') ;

    libxml_use_internal_errors(true) ;

    $objDOMDocument = new DOMDocument() ;

    $objDOMDocument->loadHTML($strElement) ;

    $arrFinance = [] ;

    $objRowItems = $objDOMDocument->getElementsByTagName('tr') ;

    if ($objRowItems->length > 0)
    {
        $intTotalRows = $objRowItems->length ;
        // $intTotalRows = 1000 ;

        for ($intRow = 0; $intRow < $intTotalRows; $intRow++)
        {
            $objColumnItems = $objDOMDocument->getElementsByTagName('tr')->item($intRow)->getElementsByTagName('td') ;

            if ($objColumnItems->length == 1)
            {
                if (count($arrFinance) > 0)
                {
                    end($arrFinance)->EndRow = $intRow ;
                }

                # string(9) " 股票  "
                # string(26) " 上市認購(售)權證  "
                # string(12) " 特別股  "
                # string(6) " ETF  "
                # string(21) " 臺灣存託憑證  "
                # string(37) " 受益證券-不動產投資信託  "

                $objFlag = new stdClass() ;
                $objFlag->Name = trim($objColumnItems->item(0)->nodeValue) ;
                $objFlag->StartRow = $intRow + 1 ;

                $arrFinance[] = $objFlag ;
            }
        }

        end($arrFinance)->EndRow = $intTotalRows ;
    }

    foreach ($arrFinance as $intIndex => $objFlag)
    {
        $arrData = [] ;

        $strFile = sprintf('%s/stock/code/%d.csv', __DIR__, $intIndex) ;

        is_dir(dirname($strFile)) || mkdir(dirname($strFile), 0777, true) ;

        if (is_file($strFile) && ! is_writable($strFile))
        {
            throw new Exception(sprintf('403-01 => Permission denied %s', $strFile), 403) ;
        }

        $fp = fopen($strFile, 'w') ;

        for ($intRow = $objFlag->StartRow; $intRow < $objFlag->EndRow; $intRow++)
        {
            $objColumnItems = $objDOMDocument->getElementsByTagName('tr')->item($intRow)->getElementsByTagName('td') ;

            $arrRow = [] ;

            for ($intColumn = 0; $intColumn < $objColumnItems->length; $intColumn++)
            {
                switch ($intColumn)
                {
                    case 0:
                        $strCell = str_replace('　',' ', $objColumnItems->item($intColumn)->nodeValue) ;

                        $arrSplit = explode(' ', $strCell) ;

                        $arrRow[] = $arrSplit[0] ;
                        $arrRow[] = $arrSplit[1] ;
                    break ;

                    default:
                        $arrRow[] = $objColumnItems->item($intColumn)->nodeValue ;
                    break ;
                }
            }

            fputcsv($fp, $arrRow) ;
        }

        fclose($fp) ;
        unset($fp) ;
    }
}
catch (Exception $e)
{
    error_log(sprintf('%s at [%s::%s]', $e->getMessage(), $e->getFile(), $e->getLine())) ;

    exit(0) ;
}




