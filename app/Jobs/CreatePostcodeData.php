<?php

namespace App\Jobs;

use App\Services\KenAllService;
use Illuminate\Foundation\Bus\Dispatchable;

class CreatePostcodeData
{
    use Dispatchable;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(KenAllService $kenAllService)
    {
        $kenAllService->convert();
    }
}
