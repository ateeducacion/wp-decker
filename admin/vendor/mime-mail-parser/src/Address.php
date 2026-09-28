<?php

/**
 * Structured email address value object and mailbox-list parser.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

declare(strict_types=1);

namespace Erseco\MimeMailParser;

/**
 * Represent a single mailbox address.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */
final readonly class Address implements \JsonSerializable
{
    /**
     * Create an address.
     *
     * @param string      $email Mailbox address.
     * @param string|null $name  Optional display name.
     */
    public function __construct(
        public string $email,
        public ?string $name = null
    ) {
    }

    /**
     * Parse a mailbox list into structured addresses.
     *
     * This intentionally implements the common mailbox forms used in email
     * headers without trying to be a complete RFC 5322 grammar.
     *
     * @param string $value Decoded mailbox-list header value.
     *
     * @return list<self>
     */
    public static function parseList(string $value): array
    {
        $addresses = [];

        foreach (self::splitList($value) as $mailbox) {
            $address = self::parseMailbox($mailbox);

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Return the address as a display string.
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->name === null || $this->name === '') {
            return $this->email;
        }

        return sprintf('%s <%s>', $this->name, $this->email);
    }

    /**
     * Convert the address to an array.
     *
     * @return array{email: string, name: string|null}
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
        ];
    }

    /**
     * Specify JSON data.
     *
     * @return array{email: string, name: string|null}
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Split a mailbox list on commas outside quoted strings and angle brackets.
     *
     * @param string $value Header value.
     *
     * @return list<string>
     */
    private static function splitList(string $value): array
    {
        $items = [];
        $current = '';
        $quoted = false;
        $escaped = false;
        $angleDepth = 0;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($escaped) {
                $current .= $character;
                $escaped = false;
                continue;
            }

            if ($quoted && $character === '\\') {
                $current .= $character;
                $escaped = true;
                continue;
            }

            if ($character === '"') {
                $quoted = !$quoted;
                $current .= $character;
                continue;
            }

            if (!$quoted && $character === '<') {
                $angleDepth++;
                $current .= $character;
                continue;
            }

            if (!$quoted && $character === '>' && $angleDepth > 0) {
                $angleDepth--;
                $current .= $character;
                continue;
            }

            if (!$quoted && $angleDepth === 0 && $character === ',') {
                if (trim($current) !== '') {
                    $items[] = trim($current);
                }

                $current = '';
                continue;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $items[] = trim($current);
        }

        return $items;
    }

    /**
     * Parse one mailbox.
     *
     * @param string $mailbox Mailbox text.
     *
     * @return self|null
     */
    private static function parseMailbox(string $mailbox): ?self
    {
        $mailbox = trim($mailbox);

        if ($mailbox === '') {
            return null;
        }

        // Strip common group syntax: "Team: a@example.com" and trailing ";".
        if (str_contains($mailbox, ':') && !str_contains($mailbox, '<')) {
            [, $mailbox] = explode(':', $mailbox, 2);
            $mailbox = trim($mailbox);
        }

        $mailbox = rtrim($mailbox, " \t\r\n;");

        if (
            preg_match(
                '/^(?<name>.*?)\s*<(?<email>[^<>]+)>$/s',
                $mailbox,
                $matches
            )
        ) {
            $email = trim($matches['email']);
            $name = self::normalizeName($matches['name']);

            return $email === '' ? null : new self($email, $name);
        }

        $email = trim($mailbox, " \t\r\n<>");

        return $email === '' ? null : new self($email);
    }

    /**
     * Normalize and decode a mailbox display name.
     *
     * @param string $name Raw display name.
     *
     * @return string|null
     */
    private static function normalizeName(string $name): ?string
    {
        $name = trim($name);

        if (
            strlen($name) >= 2
            && $name[0] === '"'
            && $name[strlen($name) - 1] === '"'
        ) {
            $name = substr($name, 1, -1);
            $name = stripcslashes($name);
        }

        $name = trim(Rfc2047::decode($name));

        return $name === '' ? null : $name;
    }
}
