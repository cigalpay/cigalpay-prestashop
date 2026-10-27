<?php

namespace Cigalpay;

/**
 * @internal Rebuilds the string CigalPay signs from a notification body.
 *
 * CigalPay signs JSON.stringify(payload, sortedTopLevelKeys): the top-level
 * keys in sorted order, and - since a key list applies to every level - only
 * those keys inside nested objects, in that same order. Values are copied as
 * they appear in the body instead of being decoded and re-encoded, so number
 * and string formatting stay byte for byte what was signed.
 */
final class Webhook
{
    /** @var string */
    private $s;
    /** @var int */
    private $i = 0;
    /** @var string[] */
    private $keys = [];

    private function __construct($s)
    {
        $this->s = $s;
    }

    /** @return string|null null when the body isn't a JSON object */
    public static function canonical($raw)
    {
        $p = new self($raw);
        try {
            $p->ws();
            $root = $p->value();
            $p->ws();
            if ($p->i !== strlen($p->s) || $root['t'] !== 'o') return null;
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        $keys = [];
        foreach ($root['v'] as $pair) $keys[$pair[0]] = true;
        $keys = array_keys($keys);
        // JS sorts by UTF-16 code units; for the ASCII keys CigalPay sends that is byte order.
        usort($keys, 'strcmp');
        $p->keys = $keys;
        return $p->emit($root);
    }

    private function emit(array $node)
    {
        if ($node['t'] === 'a') {
            return '[' . implode(',', array_map([$this, 'emit'], $node['v'])) . ']';
        }
        if ($node['t'] !== 'o') return $node['v'];
        $byKey = [];
        foreach ($node['v'] as $pair) $byKey[$pair[0]] = $pair; // last duplicate wins, as in JSON.parse
        $out = [];
        foreach ($this->keys as $k) {
            if (isset($byKey[$k])) $out[] = $byKey[$k][1] . ':' . $this->emit($byKey[$k][2]);
        }
        return '{' . implode(',', $out) . '}';
    }

    private function ws()
    {
        $this->i += strspn($this->s, " \t\n\r", $this->i);
    }

    private function fail()
    {
        throw new \UnexpectedValueException('invalid JSON');
    }

    private function value()
    {
        $c = isset($this->s[$this->i]) ? $this->s[$this->i] : '';
        if ($c === '{') return $this->obj();
        if ($c === '[') return $this->arr();
        if ($c === '"') return ['t' => 's', 'v' => $this->str()];
        if (preg_match('/\G(?:-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null)/', $this->s, $m, 0, $this->i)) {
            $this->i += strlen($m[0]);
            return ['t' => 's', 'v' => $m[0]];
        }
        $this->fail();
    }

    /** A raw string token, quotes included. */
    private function str()
    {
        if (!preg_match('/\G"(?:[^"\\\\\x00-\x1f]|\\\\(?:["\\\\\/bfnrt]|u[0-9a-fA-F]{4}))*"/', $this->s, $m, 0, $this->i)) $this->fail();
        $this->i += strlen($m[0]);
        return $m[0];
    }

    private function obj()
    {
        $this->i++;
        $pairs = [];
        $this->ws();
        if ($this->peek('}')) return ['t' => 'o', 'v' => $pairs];
        while (true) {
            $this->ws();
            $raw = $this->str();
            $this->ws();
            if (!$this->peek(':')) $this->fail();
            $this->ws();
            $pairs[] = [json_decode($raw), $raw, $this->value()];
            $this->ws();
            if ($this->peek('}')) return ['t' => 'o', 'v' => $pairs];
            if (!$this->peek(',')) $this->fail();
        }
    }

    private function arr()
    {
        $this->i++;
        $items = [];
        $this->ws();
        if ($this->peek(']')) return ['t' => 'a', 'v' => $items];
        while (true) {
            $this->ws();
            $items[] = $this->value();
            $this->ws();
            if ($this->peek(']')) return ['t' => 'a', 'v' => $items];
            if (!$this->peek(',')) $this->fail();
        }
    }

    private function peek($c)
    {
        if (isset($this->s[$this->i]) && $this->s[$this->i] === $c) {
            $this->i++;
            return true;
        }
        return false;
    }
}
