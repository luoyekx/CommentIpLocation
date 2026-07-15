<?php
    // Copyright 2022 The Ip2Region Authors. All rights reserved.
    // Use of this source code is governed by a Apache2.0-style
    // license that can be found in the LICENSE file.
    //
    // @Author Lion <chenxin619315@gmail.com>
    // @Date   2022/06/21

    namespace ip2region\xdb;
    use \Exception;

    // global constants
    const IPv4VersionNo    = 4;
    const IPv6VersionNo    = 6;
    const HeaderInfoLength = 256;
    const VectorIndexRows  = 256;
    const VectorIndexCols  = 256;
    const VectorIndexSize  = 8;

    // Util class
    class Util {
        // parse the specified IP address and return its bytes.
        // returns: NULL for failed or the packed bytes
        public static function parseIP(string $ipString): ?string {
            $flag = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
            if (!filter_var($ipString, FILTER_VALIDATE_IP, $flag)) {
                return null;
            }

            return inet_pton($ipString);
        }

        // compare two ip bytes (packed string return by parsedIP)
        // returns: -1 if ip1 < ip2, 0 if ip1 == ip2 or 1 if ip1 > ip2
        public static function ipSubCompare(string $ip1, string $buff, int $offset): int {
            $r = strcmp($ip1, substr($buff, $offset, strlen($ip1)));
            if ($r < 0) {
                return -1;
            } else if ($r > 0) {
                return 1;
            } else {
                return 0;
            }
        }

        // decode a 4bytes long with Little endian byte order from a byte buffer
        public static function le_getUint32(string $b, int $idx): int {
            $val = (ord($b[$idx])) | (ord($b[$idx+1]) << 8)
                | (ord($b[$idx+2]) << 16) | (ord($b[$idx+3]) << 24);

            // convert signed int to unsigned int if on 32 bit operating system
            if ($val < 0 && PHP_INT_SIZE == 4) {
                $val = (int) sprintf("%u", $val);
            }

            return $val;
        }

        // read a 2bytes int with litten endian byte order from a byte buffer
        public static function le_getUint16(string $b, int $idx): int {
            return ((ord($b[$idx])) | (ord($b[$idx+1]) << 8));
        }

        // load vector index from a file handle
        public static function loadVectorIndex($handle): ?string {
            if (fseek($handle, HeaderInfoLength) == -1) {
                return null;
            }

            $rLen = VectorIndexRows * VectorIndexCols * VectorIndexSize;
            $buff = fread($handle, $rLen);
            if ($buff === false) {
                return null;
            }

            if (strlen($buff) != $rLen) {
                return null;
            }

            return $buff;
        }

        // load vector index from a specified xdb file path
        public static function loadVectorIndexFromFile(string $dbFile): ?string {
            $handle = fopen($dbFile, 'rb');
            if ($handle === false) {
                return null;
            }

            $vIndex = self::loadVectorIndex($handle);
            fclose($handle);
            return $vIndex;
        }

        // load the xdb content from a file path
        public static function loadContentFromFile(string $dbFile): ?string {
            $str = file_get_contents($dbFile, false);
            if ($str === false) {
                return null;
            }
            return $str;
        }
    }

    // IPv4 version class
    class IPv4 {
        public int $id;
        public string $name;
        public int $bytes;
        public int $segmentIndexSize;

        private static ?IPv4 $C = null;
        public static function default(): IPv4 {
            if (self::$C === null) {
                // 14 = 4 + 4 + 2 + 4
                self::$C = new self(IPv4VersionNo, 'IPv4', 4, 14);
            }
            return self::$C;
        }

        public function __construct(int $id, string $name, int $bytes, int $segmentIndexSize) {
            $this->id = $id;
            $this->name = $name;
            $this->bytes = $bytes;
            $this->segmentIndexSize = $segmentIndexSize;
        }

        // compare the two ip bytes with the current version
        public function ipSubCompare(string $ip1, string $buff, int $offset): int {
            $len  = strlen($ip1);
            $eIdx = $offset + $len;
            for ($i = 0, $j = $eIdx - 1; $i < $len; $i++, $j--) {
                $i1 = ord($ip1[$i]) & 0xFF;
                $i2 = ord($buff[$j]) & 0xFF;
                if ($i1 > $i2) {
                    return 1;
                } else if ($i1 < $i2) {
                    return -1;
                }
            }

            return 0;
        }
    }

    class IPv6 {
        public int $id;
        public string $name;
        public int $bytes;
        public int $segmentIndexSize;

        private static ?IPv6 $C = null;
        public static function default(): IPv6 {
            if (self::$C === null) {
                // 38 = 16 + 16 + 2 + 4
                self::$C = new self(IPv6VersionNo, 'IPv6', 16, 38);
            }

            return self::$C;
        }

        public function __construct(int $id, string $name, int $bytes, int $segmentIndexSize) {
            $this->id = $id;
            $this->name = $name;
            $this->bytes = $bytes;
            $this->segmentIndexSize = $segmentIndexSize;
        }

        public function ipSubCompare(string $ip, string $buff, int $offset): int {
            return Util::ipSubCompare($ip, $buff, $offset);
        }
    }

    // Xdb searcher implementation
    class Searcher {
        // ip version (IPv4 or IPv6 instance)
        private object $version;

        // xdb file handle
        private $handle = null;

        // vector index in binary string.
        private ?string $vectorIndex = null;

        // xdb content buffer
        private ?string $contentBuff = null;

        /**
         * @throws Exception
         */
        public static function newWithFileOnly($version, string $dbFile): Searcher {
            return new self($version, $dbFile, null, null);
        }

        /**
         * @throws Exception
         */
        public static function newWithVectorIndex($version, string $dbFile, ?string $vIndex): Searcher {
            return new self($version, $dbFile, $vIndex, null);
        }

        /**
         * @throws Exception
         */
        public static function newWithBuffer($version, string $cBuff): Searcher {
            return new self($version, null, null, $cBuff);
        }

        /**
         * initialize the xdb searcher
         * @throws Exception
         */
        public function __construct($version, ?string $dbFile, ?string $vectorIndex = null, ?string $cBuff = null) {
            $this->version = $version;
            // check the content buffer first
            if ($cBuff !== null) {
                $this->vectorIndex = null;
                $this->contentBuff = $cBuff;
            } else {
                // open the xdb binary file
                $this->handle = fopen($dbFile, 'rb');
                if ($this->handle === false) {
                    throw new Exception(sprintf("failed to open xdb file '%s'", $dbFile));
                }

                $this->vectorIndex = $vectorIndex;
            }
        }

        /**
         * find the region info for the specified ip address.
         * @throws Exception
         */
        public function search(string $ip): string {
            $ipBytes = Util::parseIP($ip);
            if ($ipBytes === null) {
                throw new Exception("invalid ip address `{$ip}`");
            }

            return $this->searchByBytes($ipBytes);
        }

        /**
         * find the region info for the specified binary ip bytes returned by #parseIP.
         * @throws Exception
         */
        public function searchByBytes(string $ipBytes): string {
            // ip version check
            if (strlen($ipBytes) != $this->version->bytes) {
                throw new Exception("invalid ip address ({$this->version->name} expected)");
            }

            // locate the segment index block based on the vector index
            $il0 = ord($ipBytes[0]) & 0xFF;
            $il1 = ord($ipBytes[1]) & 0xFF;
            $idx = $il0 * VectorIndexCols * VectorIndexSize + $il1 * VectorIndexSize;
            if ($this->vectorIndex !== null) {
                $sPtr = Util::le_getUint32($this->vectorIndex, $idx);
                $ePtr = Util::le_getUint32($this->vectorIndex, $idx + 4);
            } else if ($this->contentBuff !== null) {
                $sPtr = Util::le_getUint32($this->contentBuff, HeaderInfoLength + $idx);
                $ePtr = Util::le_getUint32($this->contentBuff, HeaderInfoLength + $idx + 4);
            } else {
                // read the vector index block
                $buff = $this->read(HeaderInfoLength + $idx, 8);
                $sPtr = Util::le_getUint32($buff, 0);
                $ePtr = Util::le_getUint32($buff, 4);
            }

            // @Note: ptr validate, zero ptr means source data missing
            if ($sPtr == 0 || $ePtr == 0) {
                return "";
            }

            $bytes  = strlen($ipBytes);
            $dBytes = $bytes << 1;

            // binary search the segment index to get the region info
            $idxSize = $this->version->segmentIndexSize;
            $dataLen = 0;
            $dataPtr = 0;
            $l = 0;
            $h = (int) (($ePtr - $sPtr) / $idxSize);
            while ($l <= $h) {
                $m = ($l + $h) >> 1;
                $p = $sPtr + $m * $idxSize;

                // read the segment index
                $buff = $this->read($p, $idxSize);

                // compare the segment index
                if ($this->version->ipSubCompare($ipBytes, $buff, 0) < 0) {
                    $h = $m - 1;
                } else if ($this->version->ipSubCompare($ipBytes, $buff, $bytes) > 0) {
                    $l = $m + 1;
                } else {
                    $dataLen = Util::le_getUint16($buff, $dBytes);
                    $dataPtr = Util::le_getUint32($buff, $dBytes + 2);
                    break;
                }
            }

            // empty match interception.
            if ($dataLen == 0) {
                return "";
            }

            // load and return the region data
            return $this->read($dataPtr, $dataLen);
        }

        // read specified bytes from the specified index
        private function read(int $offset, int $len): string {
            // check the in-memory buffer first
            if ($this->contentBuff !== null) {
                return substr($this->contentBuff, $offset, $len);
            }

            // read from the file
            $r = fseek($this->handle, $offset);
            if ($r == -1) {
                throw new Exception("failed to fseek to {$offset}");
            }

            $buff = fread($this->handle, $len);
            if ($buff === false) {
                throw new Exception("failed to fread from {$len}");
            }

            if (strlen($buff) != $len) {
                throw new Exception("incomplete read: read bytes should be {$len}");
            }

            return $buff;
        }
    }
