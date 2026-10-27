<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/** The plugin's own tables: proposals, votes and the change history. */
final class Schema
{
    public static function proposals(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sepo_dict_proposals';
    }

    public static function votes(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sepo_dict_votes';
    }

    public static function history(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sepo_dict_history';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();

        dbDelta('CREATE TABLE ' . self::proposals() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
  dict_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT '',
  op varchar(16) NOT NULL DEFAULT '',
  target varchar(32) NOT NULL DEFAULT '',
  group_key varchar(191) NOT NULL DEFAULT '',
  term varchar(191) NOT NULL DEFAULT '',
  language varchar(100) NOT NULL DEFAULT '',
  data longtext NULL,
  base longtext NULL,
  reason text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author varchar(120) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'open',
  up int(11) NOT NULL DEFAULT 0,
  down int(11) NOT NULL DEFAULT 0,
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  closes datetime NULL DEFAULT NULL,
  closed datetime NULL DEFAULT NULL,
  closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  close_note varchar(255) NOT NULL DEFAULT '',
  history_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY entry_id (entry_id),
  KEY dict_id (dict_id),
  KEY group_key (group_key),
  KEY user_id (user_id),
  KEY closes (closes)
) $c;");

        dbDelta('CREATE TABLE ' . self::votes() . " (
  proposal_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  value tinyint(4) NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (proposal_id,user_id),
  KEY user_id (user_id)
) $c;");

        dbDelta('CREATE TABLE ' . self::history() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  action varchar(20) NOT NULL DEFAULT '',
  proposal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  summary varchar(255) NOT NULL DEFAULT '',
  prev_state longtext NULL,
  new_state longtext NULL,
  votes_up int(11) NOT NULL DEFAULT 0,
  votes_down int(11) NOT NULL DEFAULT 0,
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY entry_id (entry_id),
  KEY proposal_id (proposal_id)
) $c;");
    }

    /** Times are stored in UTC. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** A stored UTC time as a timestamp. */
    public static function ts(?string $utc): int
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return 0;
        }
        $t = strtotime($utc . ' UTC');
        return $t === false ? 0 : $t;
    }

    /** @return array<string,mixed>|null */
    public static function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $v = json_decode($json, true);
        return is_array($v) ? $v : null;
    }

    public static function encode(mixed $v): string
    {
        return (string) wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** One named lock for a short critical section; true when held. */
    public static function lock(string $name, int $wait = 5): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'sdict_' . md5($name), $wait)) === 1;
    }

    public static function unlock(string $name): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'sdict_' . md5($name)));
    }
}
