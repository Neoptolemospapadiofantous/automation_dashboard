<?php

namespace App\Support;

/**
 * Neutralizes markdown in untrusted text before it is interpolated into a
 * MailMessage line. Laravel's mail pipeline Blade-escapes each line (killing
 * raw HTML) and THEN parses it as markdown — so visitor-authored text can
 * otherwise smuggle live links ([pay here](https://phish...)), autolinked
 * bare URLs, or formatting that breaks the email's own layout.
 */
class MailText
{
    /**
     * Backslash-escape every markdown-structural character. CommonMark
     * renders a backslash-escaped punctuation char literally, so the text
     * reads unchanged. Deliberately leaves & ' " < > alone: Blade converts
     * those to HTML entities first, and escaping them here would corrupt
     * the entity instead of the character.
     */
    public static function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~:=])/', '\\\\$1', $text);
    }
}
