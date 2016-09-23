<?php

use Application\Db\Table;

class Referer extends Table
{
    protected $_name = 'referer';
    protected $_primary = ['url'];

    public function addUrl($url, $accept)
    {
        $host = @parse_url($url, PHP_URL_HOST);

        $whitelist = new Referer_Whitelist();
        $whitelisted = $whitelist->containsHost($host);

        if (!$whitelisted) {

               $this->getAdapter()->query('
                   insert into referer (host, url, count, last_date, accept)
                   values (?, ?, 1, NOW(), ?)
                   on duplicate key
                   update count=count+1, host=VALUES(host), last_date=VALUES(last_date), accept=VALUES(accept)
               ', [$host, $url, $accept]);
        }
    }

    public function isImageRequest($accept)
    {
        $result = false;

        $accept = trim($accept);
        if ($accept) {
            $medias = explode(',', $accept);
            if ($medias) {
                $firstMedia = trim($medias[0]);
                if (in_array($firstMedia, ['image/png'])) {
                    $result = true;
                }
            }
        }

        return $result;
    }
}