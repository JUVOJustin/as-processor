<?php

namespace juvo\AS_Processor;

interface Stats_Saver
{
    public function save_stats(Stats $stats): void;
}