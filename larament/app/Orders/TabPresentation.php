<?php

namespace App\Orders;

final readonly class TabPresentation
{
    /**
     * @param  list<string>  $statuses
     */
    public function __construct(
        public string $body,
        public string $creation,
        public array $statuses,
        public string $cardTitle,
    ) {}

    /**
     * @return array{body: string, creation: string, statuses: list<string>, cardTitle: string}
     */
    public function toArray(): array
    {
        return [
            'body' => $this->body,
            'creation' => $this->creation,
            'statuses' => $this->statuses,
            'cardTitle' => $this->cardTitle,
        ];
    }
}
