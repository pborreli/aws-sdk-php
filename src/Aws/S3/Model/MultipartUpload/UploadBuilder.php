<?php
/**
 * Copyright 2010-2012 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 * http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */

namespace Aws\S3\Model\MultipartUpload;

use Aws\Common\Enum\Size;
use Aws\Common\Enum\UaString as Ua;
use Aws\Common\Exception\InvalidArgumentException;
use Aws\Common\Model\MultipartUpload\AbstractUploadBuilder;
use Aws\S3\Model\Acp;

/**
 * Easily create a multipart uploader used to quickly and reliably upload a
 * large file or data stream to Amazon S3 using multipart uploads
 */
class UploadBuilder extends AbstractUploadBuilder
{
    /**
     * @var string Bucket to upload to
     */
    protected $bucket;

    /**
     * @var string Key of the object
     */
    protected $key;

    /**
     * @var int Concurrency level to transfer the parts
     */
    protected $concurrency = 1;

    /**
     * @var int Minimum part size to upload
     */
    protected $minPartSize = AbstractTransfer::MIN_PART_SIZE;

    /**
     * @var string MD5 hash of the entire body to transfer
     */
    protected $md5;

    /**
     * @var bool Whether or not to calculate the entire MD5 hash of the object
     */
    protected $calculateEntireMd5 = false;

    /**
     * @var bool Whether or not to calculate MD5 hash of each part
     */
    protected $calculatePartMd5 = true;

    /**
     * @var Acp Acp to use with the object
     */
    protected $acp;

    /**
     * Set the bucket to upload the object to
     *
     * @param string $bucket Name of the bucket
     *
     * @return self
     */
    public function setBucket($bucket)
    {
        $this->bucket = $bucket;

        return $this;
    }

    /**
     * Set the key of the object
     *
     * @param string $key Key of the object to upload
     *
     * @return self
     */
    public function setKey($key)
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Set the minimum acceptable part size
     *
     * @param int $minSize Minimum acceptable part size
     *
     * @return self
     */
    public function setMinPartSize($minSize)
    {
        $this->minPartSize = (int) max((int) $minSize, AbstractTransfer::MIN_PART_SIZE);

        return $this;
    }

    /**
     * Set the concurrency level to use when uploading parts. This affects how
     * many parts are uploaded in parallel. You must use a local file as your
     * data source when using a concurrency greater than 1
     *
     * @param int $concurrency Concurrency level
     *
     * @return self
     */
    public function setConcurrency($concurrency)
    {
        $this->concurrency = $concurrency;

        return $this;
    }

    /**
     * Explicitly set the MD5 hash of the entire body
     *
     * @param string $md5 MD5 hash of the entire body
     *
     * @return self
     */
    public function setMd5($md5)
    {
        $this->md5 = $md5;

        return $this;
    }

    /**
     * Set to true to have the builder calculate the MD5 hash of the entire data
     * source before initiating a multipart upload (this could be an expensive
     * operation). This setting can ony be used with seekable data sources.
     *
     * @param bool $calculateMd5 Set to true to calculate the MD5 hash of the body
     *
     * @return self
     */
    public function calculateMd5($calculateMd5)
    {
        $this->calculateEntireMd5 = (bool) $calculateMd5;

        return $this;
    }

    /**
     * Specify whether or not to calculate the MD5 hash of each uploaded part.
     * This setting defaults to true.
     *
     * @param bool $usePartMd5 Set to true to calculate the MD5 has of each part
     *
     * @return self
     */
    public function calculatePartMd5($usePartMd5)
    {
        $this->calculatePartMd5 = (bool) $usePartMd5;

        return $this;
    }

    /**
     * Set the ACP to use on the object
     *
     * @param Acp $acp ACP to set on the object
     *
     * @return self
     */
    public function setAcp(Acp $acp)
    {
        $this->acp = $acp;

        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws InvalidArgumentException when attempting to resume a transfer using a non-seekable stream
     * @throws InvalidArgumentException when missing required properties (bucket, key, client, source)
     */
    public function build()
    {
        if ($this->state instanceof TransferState) {
            $params = $this->state->getUploadId()->toParams();
            $this->bucket = isset($params['Bucket']) ? $params['Bucket'] : $this->bucket;
            $this->key = isset($params['Key']) ? $params['Key'] : $this->key;
        }

        if (!$this->bucket || !$this->key || !$this->client || !$this->source) {
            throw new InvalidArgumentException('You must specify a bucket, key, client, and source.');
        }

        if ($this->state && !$this->source->isSeekable()) {
            throw new InvalidArgumentException('You cannot resume a transfer using a non-seekable source.');
        }

        // If no state was set, then create one by initiating or loading a multipart upload
        if (is_string($this->state)) {
            $this->state = TransferState::fromUploadId($this->client, UploadId::fromParams(array(
                'Bucket'   => $this->bucket,
                'Key'      => $this->key,
                'UploadId' => $this->state
            )));
        } elseif (!$this->state) {
            $this->state = $this->initiateMultipartUpload();
        }

        $options = array(
            'min_part_size' => $this->minPartSize,
            'part_md5'      => (bool) $this->calculatePartMd5,
            'concurrency'   => $this->concurrency
        );

        return $this->concurrency > 1
            ? new ParallelTransfer($this->client, $this->state, $this->source, $options)
            : new SerialTransfer($this->client, $this->state, $this->source, $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function initiateMultipartUpload()
    {
        $params = array(
            'Bucket'   => $this->bucket,
            'Key'      => $this->key,
        );

        $command = $this->client->getCommand('CreateMultipartUpload', array_replace($params, array(
            'command.headers' => $this->headers,
            'ACP'             => $this->acp,
            Ua::OPTION        => Ua::MULTIPART_UPLOAD
        )));

        // Calculate the MD5 hash if none was set and it is asked of the builder
        if ($this->calculateEntireMd5) {
            $this->md5 = $this->source->getContentMd5();
        }

        // If an MD5 is specified, then add it to the custom headers of the request
        // so that it will be returned when downloading the object from Amazon S3
        if ($this->md5) {
            $command['Metadata'] = array(
                'x-amz-Content-MD5' => $this->md5
            );
        }

        $result = $command->execute();

        // Create a new state based on the initiated upload
        $params['UploadId'] = $result['UploadId'];
        return new TransferState(UploadId::fromParams($params));
    }
}
