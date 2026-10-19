<?php
use installer\interfaces\package\actions\IPostUpdate;

final class QuillThemeBridgeUpdate implements IPostUpdate
{
    public function postUpdate(int $packageId, string $packageName): void
    {
        \QuillThemeBridge\services\Schema::install($packageId);
    }
}
