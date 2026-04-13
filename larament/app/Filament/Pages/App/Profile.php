<?php

namespace App\Filament\Pages\App;

use App\Filament\Actions\GeneratePasswordAction;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Profile extends EditProfile
{
    public function getBreadcrumbs(): array
    {
        return [
            null => __('Dashboard'),
            'profile' => __('Profile'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        /** @var TextInput $passwordComponent */
        $passwordComponent = $this->getPasswordFormComponent();

        return $schema->components([
            Section::make()
                ->inlineLabel(false)
                ->schema([
                    $this->getNameFormComponent(),
                    $this->getEmailFormComponent(),
                    $passwordComponent->suffixActions([
                        GeneratePasswordAction::make(),
                    ]),
                    $this->getPasswordConfirmationFormComponent(),
                ]),
        ]);
    }
}
