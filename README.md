- Create, update, and delete multiple morphed models
- Child model automatically includes its parent(s) fields
- Extend database tables just like PHP classes

## Installation

This packages require Laravel to be installed

```bash
composer install k1zang/extendable-tables
```

## Example

User model:

```php
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
}
```

Profile model:

```php
use App\Support\Traits\CanExtend;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use CanExtend;

    protected static function boot()
    {
        parent::boot();
        self::extends(User::class, 'user');
    }
}
```

## Contributing

Pull requests are welcome. For major changes, please open an issue first
to discuss what you would like to change.

Please make sure to update tests as appropriate.

## License

[MIT](https://choosealicense.com/licenses/mit/)
