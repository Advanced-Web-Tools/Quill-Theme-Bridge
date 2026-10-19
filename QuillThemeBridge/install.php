<?php
use installer\interfaces\package\actions\IPostInstall;

final class QuillThemeBridgeInstall implements IPostInstall
{
    public function postInstall(int $packageId, string $packageName): bool
    {
        \QuillThemeBridge\services\Schema::install($packageId);
        return true;
    }
}
