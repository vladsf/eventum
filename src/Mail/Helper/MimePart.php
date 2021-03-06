<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Mail\Helper;

use Laminas\Mail\Header\HeaderWrap;
use Laminas\Mime;

class MimePart extends Mime\Part
{
    private const CHARSET = 'UTF-8';

    public static function create($content, $type, $charset = self::CHARSET): self
    {
        $part = new self($content);
        $part->type = $type;
        $part->charset = $charset;

        return $part;
    }

    /**
     * @param string $content
     * @return MimePart
     */
    public static function createTextPart($content): self
    {
        return self::create($content, Mime\Mime::TYPE_TEXT);
    }

    /**
     * @param string $content
     * @param string $type
     * @param string $filename
     * @return Mime\Part
     */
    public static function createAttachmentPart($content, $type, $filename): self
    {
        // For now, Encode filenames with Quoted Printable
        // @see https://github.com/eventum/eventum/issues/1078#issuecomment-825755772
        $filename = HeaderWrap::mimeEncodeValue($filename, self::CHARSET);

        return self::create($content, $type)
            ->setDisposition(Mime\Mime::DISPOSITION_ATTACHMENT)
            ->setEncoding(Mime\Mime::ENCODING_BASE64)
            ->setFileName($filename);
    }
}
