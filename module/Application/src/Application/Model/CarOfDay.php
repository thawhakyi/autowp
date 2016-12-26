<?php

namespace Application\Model;

use Application\Db\Table;
use Application\Model\DbTable\Picture;
use Application\Model\DbTable\Vehicle;
use Application\ItemNameFormatter;

use Zend_Db_Expr;
use Zend_Oauth_Token_Access;
use Zend_Service_Twitter;

class CarOfDay
{
    /**
     * @var Table
     */
    private $table;

    /**
     * @var ItemNameFormatter
     */
    private $itemNameFormatter;

    public function __construct(ItemNameFormatter $itemNameFormatter)
    {
        $this->itemNameFormatter = $itemNameFormatter;

        $this->table = new Table([
            'name'    => 'of_day',
            'primary' => 'day_date'
        ]);
    }

    public function getCarOfDayCadidate()
    {
        $db = $this->table->getAdapter();
        $sql = '
            SELECT c.id, count(p.id) AS p_count
            FROM cars AS c
                INNER JOIN item_parent_cache AS cpc ON c.id=cpc.parent_id
                INNER JOIN picture_item ON cpc.item_id = picture_item.item_id
                INNER JOIN pictures AS p ON picture_item.picture_id=p.id
            WHERE p.type=? AND p.status=?
                AND (c.begin_year AND c.end_year OR c.begin_model_year AND c.end_model_year)
                AND c.id NOT IN (SELECT car_id FROM of_day WHERE car_id)
            GROUP BY c.id
            HAVING p_count >= 5
            ORDER BY RAND()
            LIMIT 1
        ';
        return $db->fetchRow($sql, [Picture::VEHICLE_TYPE_ID, Picture::STATUS_ACCEPTED]);
    }

    public function pick()
    {
        $dayRow = $this->table->fetchRow([
            'day_date = CURDATE()'
        ]);

        $db = $this->table->getAdapter();

        if (! $dayRow) {
            $dayRow = $this->table->createRow([
                'day_date' => new Zend_Db_Expr('CURDATE()')
            ]);
        }

        if (! $dayRow['car_id']) {
            $row = $this->getCarOfDayCadidate();
            if ($row) {
                print $row['id']  ."\n";

                $dayRow->car_id = $row['id'];
                $dayRow->save();
            }
        }
    }

    public function getCurrent()
    {
        $row = $this->table->fetchRow([
            'day_date <= CURDATE()'
        ], 'day_date DESC');

        return $row ? $row->car_id : null;
    }

    private function pictureByPerspective($pictureTable, $car, $perspective)
    {
        $select = $pictureTable->select(true)
            ->where('pictures.status IN (?)', [Picture::STATUS_ACCEPTED, Picture::STATUS_NEW])
            ->join('picture_item', 'pictures.id = picture_item.picture_id', null)
            ->join('item_parent_cache', 'picture_item.item_id = item_parent_cache.item_id', null)
            ->where('item_parent_cache.parent_id = ?', $car->id)
            ->order([
                'pictures.width DESC', 'pictures.height DESC'
            ])
            ->limit(1);
        if ($perspective) {
            $select->where('picture_item.perspective_id = ?', $perspective);
        }
        return $pictureTable->fetchRow($select);
    }

    public function putCurrentToTwitter(array $twOptions)
    {
        $dayRow = $this->table->fetchRow([
            'day_date = CURDATE()',
            'not twitter_sent'
        ]);

        if (! $dayRow) {
            print 'Day row not found or already sent' . PHP_EOL;
            return;
        }

        $carTable = new Vehicle();

        $car = $carTable->fetchRow([
            'id = ?' => (int)$dayRow->car_id
        ]);

        if (! $car) {
            print 'Car of day not found' . PHP_EOL;
            return;
        }

        $pictureTable = new Picture();

        /* Hardcoded perspective priority list */
        $perspectives = [10, 1, 7, 8, 11, 3, 7, 12, 4, 8];

        foreach ($perspectives as $perspective) {
            $picture = $this->pictureByPerspective($pictureTable, $car, $perspective);
            if ($picture) {
                break;
            }
        }

        if (! $picture) {
            $picture = $this->pictureByPerspective($pictureTable, $car, false);
        }

        if (! $picture) {
            print 'Picture not found' . PHP_EOL;
            return;
        }

        $url = 'http://wheelsage.org/picture/' . ($picture->identity ? $picture->identity : $picture->id);

        $text = sprintf(
            'Vehicle of the day: %s %s',
            $this->itemNameFormatter->format($car->getNameData('en'), 'en'),
            $url
        );

        $token = new Zend_Oauth_Token_Access();
        $token->setParams($twOptions['token']);

        $twitter = new Zend_Service_Twitter([
            'username'     => $twOptions['username'],
            'accessToken'  => $token,
            'oauthOptions' => $twOptions['oauthOptions']
        ]);

        $response = $twitter->statusesUpdate($text);

        if ($response->isSuccess()) {
            $dayRow->twitter_sent = true;
            $dayRow->save();

            print 'ok' . PHP_EOL;
        } else {
            print_r($response->getErrors());
        }
    }
}
