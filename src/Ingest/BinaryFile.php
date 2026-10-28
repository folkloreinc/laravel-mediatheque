<?php

namespace Folklore\Mediatheque\Ingest;

/**
 * Reads bytes and integers at offsets of an open file, for GpsStripper.
 * Reading past the end gives fewer bytes, and 0 for an integer.
 *
 * @internal
 */
final readonly class BinaryFile
{
    /**
     * @param  resource  $handle
     */
    public function __construct(
        private mixed $handle,
        public int $size,
    ) {}

    public function read(int $offset, int $length): string
    {
        if ($length <= 0 || $offset < 0 || $offset >= $this->size) {
            return '';
        }

        fseek($this->handle, $offset);

        return (string) fread($this->handle, $length);
    }

    public function u16be(int $offset): int
    {
        return $this->unpack('n', $offset, 2);
    }

    public function u16le(int $offset): int
    {
        return $this->unpack('v', $offset, 2);
    }

    public function u32be(int $offset): int
    {
        return $this->unpack('N', $offset, 4);
    }

    public function u32le(int $offset): int
    {
        return $this->unpack('V', $offset, 4);
    }

    /**
     * A big-endian unsigned integer of 0 to 8 bytes.
     */
    public function uint(int $offset, int $bytes): int
    {
        $value = 0;
        foreach (str_split(str_pad($this->read($offset, $bytes), $bytes, "\0")) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $bytes === 0 ? 0 : $value;
    }

    private function unpack(string $format, int $offset, int $length): int
    {
        $bytes = $this->read($offset, $length);
        if (strlen($bytes) < $length) {
            return 0;
        }

        $value = unpack($format, $bytes);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }
}
