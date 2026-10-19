<?php
namespace QuillThemeBridge\services;

use URPC\api\URPCBlockDescriptor;
use URPC\api\contracts\IURPCBlockDescriptor;

/** Runtime-only URPC extension. No fields are added to saved Quill JSON. */
final class RuntimeDescriptor extends URPCBlockDescriptor
{
    public function __construct(IURPCBlockDescriptor $source, public readonly array $retainedAssets)
    {
        parent::__construct($source->getBlockName(), $source->getBlockProps(), $source->getBlockRenderer(), $source->getSourceKey());
        $this->setBlockUid($source->getBlockUid());
    }
}
