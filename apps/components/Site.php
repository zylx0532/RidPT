<?php
/**
 * Created by PhpStorm.
 * User: Rhilip
 * Date: 7/28/2019
 * Time: 5:04 PM
 */

namespace apps\components;

use apps\models;
use apps\libraries\Mailer;
use apps\libraries\Constant;

use Rid\Http\View;
use Rid\Base\Component;
use Rid\Utils\ClassValueCacheUtils;

use RuntimeException;

class Site extends Component
{
    use ClassValueCacheUtils;

    protected $users = [];
    protected $torrents = [];
    protected $map_username_to_id = [];

    const LOG_LEVEL_NORMAL = 'normal';
    const LOG_LEVEL_MOD = 'mod';
    const LOG_LEVEL_SYSOP = 'sysop';
    const LOG_LEVEL_LEADER = 'leader';

    public function onRequestBefore()
    {
        parent::onRequestBefore();

        $this->users = [];
        $this->torrents = [];
        $this->map_username_to_id = [];
    }

    protected function getCacheNameSpace(): string
    {
        return 'Site:hash:runtime_value';
    }

    public function getTorrent($tid)
    {
        if (array_key_exists($tid, $this->torrents)) {
            $torrent = $this->torrents[$tid];
        } else {
            $torrent = new models\Torrent($tid);  // TODO Handing if this torrent id does not exist
            $this->torrents[$tid] = $torrent;
        }
        return $torrent;
    }

    /**
     * @param $uid
     * @return models\User|bool return False means this user is not exist
     */
    public function getUser($uid)
    {
        if (array_key_exists($uid, $this->users)) {
            $user = $this->users[$uid];
        } else {
            $user = new models\User($uid);  // TODO Handing if this user id does not exist
            $this->users[$uid] = $user;
        }
        return $user;
    }

    /**
     * @param $username
     * @return models\User|bool
     */
    public function getUserByUserName($username)
    {
        if (array_key_exists($username, $this->map_username_to_id)) {
            $uid = $this->map_username_to_id[$username];
        } else {
            $uid = app()->redis->hGet(Constant::mapUsernameToId, $username);
            if (false === $uid) {
                $uid = app()->pdo->createCommand('SELECT id FROM `users` WHERE LOWER(`username`) = LOWER(:uname) LIMIT 1;')->bindParams([
                    'uname' => $username
                ])->queryScalar() ?: 0;  // 0 means this username is not exist ???
                app()->redis->hSet(Constant::mapUsernameToId, $username, $uid);
                $this->map_username_to_id[$username] = $uid;
            }
        }

        return $this->getUser($uid);
    }

    public function writeLog($msg, $level = self::LOG_LEVEL_NORMAL)
    {
        app()->pdo->createCommand('INSERT INTO `site_log`(`create_at`,`msg`, `level`) VALUES (CURRENT_TIMESTAMP, :msg, :level)')->bindParams([
            'msg' => $msg, 'level' => $level
        ])->execute();
    }

    public function sendPM($sender, $receiver, $subject, $msg, $save = 'no', $location = 1)
    {
        app()->pdo->createCommand('INSERT INTO `messages` (`sender`,`receiver`,`add_at`, `subject`, `msg`, `saved`, `location`) VALUES (:sender,:receiver,`CURRENT_TIMESTAMP`,:subject,:msg,:save,:location)')->bindParams([
            'sender' => $sender, 'receiver' => $receiver,
            'subject' => $subject, 'msg' => $msg,
            'save' => $save, 'location' => $location
        ])->execute();

        app()->redis->hDel(Constant::userContent($receiver), 'unread_message_count', 'inbox_count');
        if ($sender != 0) app()->redis->hDel(Constant::userContent($sender), 'outbox_count');
    }

    public function sendEmail($receivers, $subject, $template, $data = [])
    {
        $mail_body = (new View(false))->render($template, $data);
        $mail_sender = Mailer::newInstanceByConfig('libraries.[mailer]');
        $mail_sender->send($receivers, $subject, $mail_body);
    }

    public function getQualityTableList()
    {
        return [
            'audio' => 'Audio Codec',  // TODO i18n title
            'codec' => 'Codec',
            'medium' => 'Medium',
            'resolution' => 'Resolution'
        ];
    }

    public static function ruleCategory(): array
    {
        if (false === $cats = config('runtime.enabled_torrent_category')) {
            $cats = [];
            $cats_raw = app()->pdo->createCommand('SELECT * FROM `categories` WHERE `id` > 0 ORDER BY `full_path`')->queryAll();

            foreach ($cats_raw as $cat_raw) $cats[$cat_raw['id']] = $cat_raw;
            app()->config->set('runtime.enabled_torrent_category', $cats, 'json');
        }

        return $cats ?: [];
    }

    public static function CategoryDetail($cat_id): array
    {
        return static::ruleCategory()[$cat_id];
    }

    public static function ruleCanUsedCategory(): array
    {
        return array_filter(static::ruleCategory(), function ($cat) {
            return $cat['enabled'] = 1;
        });
    }

    public function ruleQuality($quality): array
    {
        if (!in_array($quality, array_keys($this->getQualityTableList()))) throw new RuntimeException('Unregister quality : ' . $quality);
        if (false === $data = config('runtime.enabled_quality_' . $quality)) {
            /** @noinspection SqlResolve */
            $data = app()->pdo->createCommand("SELECT * FROM `quality_$quality` WHERE `id` > 0 AND `enabled` = 1 ORDER BY `sort_index`,`id`")->queryAll();
            app()->config->set('runtime.enabled_quality_' . $quality, $data, 'json');
        }
        return $data ?: [];
    }

    public function ruleTeam(): array
    {
        if (false === $data = config('runtime.enabled_teams')) {
            /** @noinspection SqlResolve */
            $data = app()->pdo->createCommand('SELECT * FROM `teams` WHERE `id` > 0 AND `enabled` = 1 ORDER BY `sort_index`,`id`')->queryAll();
            app()->config->set('runtime.enabled_teams', $data, 'json');
        }

        return $data ?: [];
    }

    public function ruleCanUsedTeam(): array
    {
        return array_filter($this->ruleTeam(), function ($team) {
            return app()->auth->getCurUser()->getClass() >= $team['class_require'];
        });
    }

    /**
     * @return array like [<tag1> => <tag1_class_name>, <tag2> => <tag2_class_name>]
     */
    public function rulePinnedTags(): array
    {
        if (false === $data = config('runtime.pinned_tags')) {
            /** @noinspection SqlResolve */
            $raw = app()->pdo->createCommand('SELECT `tag`, `class_name` FROM `tags` WHERE `pinned` = 1;')->queryAll();
            $data = array_column($raw, 'class_name', 'tag');
            app()->config->set('runtime.pinned_tags', $data, 'json');
        }

        return $data;
    }

    public static function fetchUserCount(): int
    {
        return app()->pdo->createCommand('SELECT COUNT(`id`) FROM `users`')->queryScalar();
    }
}
