<?php

namespace App\Console\Commands;

use App\Exceptions\CardException;
use App\Support\CardUid;
use App\Support\SisCardFormat;
use Illuminate\Console\Command;

class ShowSisCardFormats extends Command
{
    protected $signature = 'sis:card-formats {rfid : The rfid value exactly as it is stored in the SIS}';

    protected $description = 'Shows what the canteen would store for a SIS card number in each format, to compare with the Bind card box.';

    public function handle(): int
    {
        $rfid = (string) $this->argument('rfid');

        $this->line("SIS value: {$rfid}");
        $this->line('Tap the SAME card on the canteen reader (Students -> Bind card) and see which line below matches what it types.');
        $this->newLine();

        foreach (SisCardFormat::MODES as $mode) {
            try {
                $result = CardUid::normalize(SisCardFormat::convert($rfid, $mode));
            } catch (CardException) {
                $result = '(not a valid card number in this format)';
            }

            $this->line(str_pad($mode, 26) . $result);
        }

        $this->newLine();
        $this->line('Set the matching mode in .env as SIS_CARD_FORMAT=<mode>.');

        return self::SUCCESS;
    }
}
