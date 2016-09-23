<?php

use Application\Db\Table\Row;

use Autowp\Filter\Filename\Safe;
use Autowp\Image\Storage\Request;

use Exception;

class Picture_Row extends Row
{
    private $_caption_cache = [];

    private $perspectiveTable;
    private $perspectivePrefix = [];
    private $prefixedPerspectives = [5, 6, 17, 20, 21, 22, 23];

    private function getPerspectiveTable()
    {
        return $this->perspectiveTable
        ? $this->perspectiveTable
        : $this->perspectiveTable = new Perspectives();
    }

    private static function mbUcfirst($str)
    {
        return mb_strtoupper(mb_substr($str, 0, 1)) . mb_substr($str, 1);
    }

    private function getPerspectivePrefix($id, $language, $translator)
    {
        if (in_array($id, $this->prefixedPerspectives)) {
            if (!isset($this->perspectivePrefix[$id][$language])) {
                $row = $this->getPerspectiveTable()->find($id)->current();
                if ($row) {
                    $name = $translator->translate($row->name, 'default', $language);
                    $this->perspectivePrefix[$id][$language] = self::mbUcfirst($name) . ' ';
                } else {
                    $this->perspectivePrefix[$id][$language] = '';
                }
            }

            return $this->perspectivePrefix[$id][$language];
        }

        return '';
    }

    public function getCaption(array $options = [])
    {
        if ($this->name) {
            return $this->name;
        }

        if (!isset($options['translator'])) {
            throw new Exception('`translator` expected');
        }

        $language = isset($options['language']) ? $options['language'] : 'en';
        $translator = $options['translator'];

        if (isset($this->_caption_cache[$language])) {
            return $this->_caption_cache[$language];
        }

        $caption = null;

        switch ($this->type) {
            case Picture::CAR_TYPE_ID:
                $car = $this->findParentCars();
                if ($car) {
                    $caption = $this->getPerspectivePrefix($this->perspective_id, $language, $translator) .
                    $car->getFullName($language);
                }
                break;

            case Picture::ENGINE_TYPE_ID:
                $engine = $this->findParentEngines();
                $caption = 'Двигатель '.($engine ? ' '.$engine->caption: '');
                break;

            case Picture::LOGO_TYPE_ID:
                $brand = $this->findParentBrands();
                $caption = 'Логотип '.($brand ? ' '.$brand->getLanguageName($language) : '');
                break;

            case Picture::MIXED_TYPE_ID:
                $brand = $this->findParentBrands();
                $caption = ($brand ? $brand->getLanguageName($language).' ' : '').' Разное';
                break;

            case Picture::UNSORTED_TYPE_ID:
                $brand = $this->findParentBrands();
                $caption = ($brand ? $brand->getLanguageName($language) : 'Несортировано');
                break;

            case Picture::FACTORY_TYPE_ID:
                $factory = $this->findParentFactory();
                if ($factory) {
                    $caption = $factory->name;
                }
                break;
        }

        if (!$caption) {
            $caption = 'Изображение №'.$this->id;
        }

        $this->_caption_cache[$language] = $caption;
        return $caption;
    }

    /**
     * @deprecated
     * @param string $absolute
     * @return string
     */
    public function getModerUrl($absolute = false)
    {
        return ($absolute ? HOST : '/').'moder/car/?car_id='.$this->id;
    }

    private static function between($a, $min, $max)
    {
        return ($min <= $a) && ($a <= $max);
    }

    public static function checkCropParameters($options)
    {
        // проверяем установлены ли границы обрезания
        // проверяем верные ли значения границ обрезания
        return  !is_null($options['crop_left']) && !is_null($options['crop_top']) &&
        !is_null($options['crop_width']) && !is_null($options['crop_height']) &&
        self::between($options['crop_left'], 0, $options['width']) &&
        self::between($options['crop_width'], 1, $options['width']) &&
        self::between($options['crop_top'], 0, $options['height']) &&
        self::between($options['crop_height'], 1, $options['height']);
    }

    public function cropParametersExists()
    {
        return self::checkCropParameters($this->toArray());
    }

    /**
     * @throws Exception
     * @return string
     */
    public function getFileNamePattern()
    {
        $result = rand(1, 9999);

        $filenameFilter = new Safe();

        switch ($this->type) {
            case Picture::LOGO_TYPE_ID:
                $brand = $this->findParentBrands();
                if ($brand) {
                    $catname = $filenameFilter->filter($brand->folder);
                    $firstChar = mb_substr($catname, 0, 1);
                    $result = $firstChar . '/' . $catname.'/logotypes/'.$catname.'_logo';
                }
                break;

            case Picture::MIXED_TYPE_ID:
                $brand = $this->findParentBrands();
                if ($brand) {
                    $catname = $filenameFilter->filter($brand->folder);
                    $firstChar = mb_substr($catname, 0, 1);
                    $result = $firstChar . '/' . $catname.'/mixed/'.$catname.'_mixed';
                }
                break;

            case Picture::UNSORTED_TYPE_ID:
                $brand = $this->findParentBrands();
                if ($brand) {
                    $catname = $filenameFilter->filter($brand->folder);
                    $firstChar = mb_substr($catname, 0, 1);
                    $result = $firstChar . '/' . $catname . '/unsorted/' . $catname.'_unsorted';
                }
                break;

            case Picture::CAR_TYPE_ID:
                $car = $this->findParentCars();
                if ($car) {
                    $carCatname = $filenameFilter->filter($car->caption);

                    $brandTable = new Brands();

                    $brands = $brandTable->fetchAll(
                        $brandTable->select(true)
                            ->join('brands_cars', 'brands.id = brands_cars.brand_id', null)
                            ->join('car_parent_cache', 'brands_cars.car_id = car_parent_cache.parent_id', null)
                            ->where('car_parent_cache.car_id = ?', $car->id)
                    );

                    $sBrands = [];
                    foreach ($brands as $brand) {
                        $sBrands[$brand->id] = $brand;
                    }

                    if (count($sBrands) > 1) {
                        $f = [];
                        foreach ($sBrands as $brand) {
                            $f[] = $filenameFilter->filter($brand->folder);
                        }
                        $f = array_unique($f);
                        sort($f, SORT_STRING);

                        $carFolder = $carCatname;
                        foreach ($f as $i) {
                            $carFolder = str_replace($i, '', $carFolder);
                        }

                        $carFolder = str_replace('__', '_', $carFolder);
                        $carFolder = trim($carFolder, '_-');

                        $brandsFolder = implode('/', $f);
                        $firstChar = mb_substr($brandsFolder, 0, 1);

                        $result = $firstChar . '/' . $brandsFolder .'/'.$carFolder.'/'.$carCatname;
                    } else {

                        if (count($sBrands) == 1) {
                            $sBrandsA = array_values($sBrands);
                            $brand = $sBrandsA[0];

                            $brandFolder = $filenameFilter->filter($brand->folder);
                            $firstChar = mb_substr($brandFolder, 0, 1);

                            $carFolder = $carCatname;
                            $carFolder = trim(str_replace($brandFolder, '', $carFolder), '_-');

                            $result = implode('/', [
                                $firstChar,
                                $brandFolder,
                                $carFolder,
                                $carCatname
                            ]);
                        } else {
                            $carFolder = $filenameFilter->filter($car->caption);
                            $firstChar = mb_substr($carFolder, 0, 1);
                            $result = $firstChar . '/' . $carFolder.'/'.$carCatname;
                        }
                    }
                }
                break;

            case Picture::ENGINE_TYPE_ID:
                $engine = $this->findParentEngines();
                if ($engine) {
                    $result = implode('/', [
                        'engines',
                        $filenameFilter->filter($engine->getMetaCaption())
                    ]);
                }
                break;

            case Picture::FACTORY_TYPE_ID:
                $factory = $this->findParentFactory();
                if ($factory) {
                    $result = implode('/', [
                        'factories',
                        $filenameFilter->filter($factory->name)
                    ]);
                }
                break;

            default:
                throw new Exception("Unknown picture type [{$this->type}]");
        }

        $result = str_replace('//', '/', $result);

        return $result;
    }

    /**
     * @deprecated
     * @param string $ext
     * @return string
     */
    public function getFileNameTemplate($ext)
    {
        return $this->getFileNamePattern() . '_%d.' . $ext;
    }

    protected function _delete()
    {
        $comments = new Comment_Message();
        $comments->delete([
            'type_id = ?' => Comment_Message::PICTURES_TYPE_ID,
            'item_id = ?' => $this->id,
        ]);

        //$this->flushFormatImages();

        //$this->removeSigned();
        //$this->removePicture280();
        //$this->removeThumb();
        //$this->removePod();
        //$this->removeSource();


    }

    public function getImageOptions($col)
    {
        $options = [];

        if ($this->cropParametersExists()) {
            $options['crop'] = [
                'left'   => $this->crop_left,
                'top'    => $this->crop_top,
                'width'  => $this->crop_width,
                'height' => $this->crop_height
            ];
        }

        return $options;
    }

    protected function postImageFormat($imageRow, $format)
    {
        if ($format->id == 8) {

            $image = $imageRow->getImage('image');

            $caption = $this->getCaption();

            $cmd = sprintf(
                "convert %s -gravity northwest " .
                "-pointsize 20 " .
                "-stroke '#000C' -strokewidth 2 -size 342x caption:%s " .
                "-stroke none -fill white -size 342x caption:%s %s",
                escapeshellarg($image['filepath']),
                escapeshellarg($caption),
                escapeshellarg($caption),
                escapeshellarg($image['filepath'])
            );
            $code = exec($cmd);

            print $cmd;
        }
    }

    public function refreshRatio()
    {
        $votes = new Votes();

        $row = $votes->getAdapter()->fetchRow(
            $votes->select()
                ->from($votes, [
                    'sum'   =>  new Zend_Db_Expr('SUM(summary)'),
                    'cnt'   =>  new Zend_Db_Expr('SUM(count)')
                ])
                ->where('picture_id = ?', $this->id)
        );

        if ($row) {
            $this->setFromArray([
                'ratio' =>  $row['cnt'] > 0 ? $row['sum']/$row['cnt'] : 0,
                'votes' =>  $row['cnt']
            ]);
        }

        $row = $votes->getAdapter()->fetchRow(
            $votes->select()
                ->from($votes, [
                    'sum'   =>  new Zend_Db_Expr('SUM(summary)'),
                    'cnt'   =>  new Zend_Db_Expr('SUM(count)')
                ])
                ->where('picture_id = ?', $this->id)
                ->where('day_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')
        );

        if ($row) {
            $this->setFromArray([
                'active_ratio'  =>  $row['cnt'] > 0 ? $row['sum']/$row['cnt'] : 0,
                'active_votes'  =>  $row['cnt']
            ]);
        }

        $this->save();

    }

    /**
     * @return Request
     */
    public function getFormatRequest()
    {
        return self::buildFormatRequest($this->toArray());
    }

    /**
     * @param array $options
     * @return Request
     */
    public static function buildFormatRequest(array $options)
    {
        $defaults = [
            'image_id'    => null,
            'crop_left'   => null,
            'crop_top'    => null,
            'crop_width'  => null,
            'crop_height' => null
        ];
        $options = array_replace($defaults, $options);

        $request = [
            'imageId' => $options['image_id']
        ];
        if (self::checkCropParameters($options)) {
            $request['crop'] = [
                'left'   => $options['crop_left'],
                'top'    => $options['crop_top'],
                'width'  => $options['crop_width'],
                'height' => $options['crop_height']
            ];
        }

        return new Request($request);
    }

    public function canAccept()
    {
        if (!in_array($this->status, [Picture::STATUS_NEW, Picture::STATUS_INBOX])) {
            return false;
        }

        $moderVoteTable = new Picture_Moder_Vote();
        $deleteVote = $moderVoteTable->fetchRow([
            'picture_id = ?' => $this->id,
            'vote = 0'
        ]);
        if ($deleteVote) {
            return false;
        }

        return true;
    }

    public function canDelete()
    {
        if (!in_array($this->status, [Picture::STATUS_NEW, Picture::STATUS_INBOX])) {
            return false;
        }

        $moderVoteTable = new Picture_Moder_Vote();
        $acceptVote = $moderVoteTable->fetchRow([
            'picture_id = ?' => $this->id,
            'vote > 0'
        ]);
        if ($acceptVote) {
            return false;
        }

        return true;
    }
}