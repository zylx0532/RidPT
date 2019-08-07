<?php
/**
 * Created by PhpStorm.
 * User: Rhilip
 * Date: 8/7/2019
 * Time: 7:22 PM
 */

namespace apps\models\form\Torrent;

use apps\models\form\Traits\isValidTorrentTrait;

use Rid\Validators\Validator;

class DetailsForm extends Validator
{

    protected $_autoload_data = true;
    protected $_autoload_data_from = ['get'];

    use isValidTorrentTrait;

    public static function inputRules()
    {
        return [
            'id' => 'required | Integer'
        ];
    }

    public static function callbackRules()
    {
        return ['isExistTorrent'];
    }
}
