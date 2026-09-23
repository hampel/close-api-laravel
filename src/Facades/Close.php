<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Facades;

use Hampel\CloseApi\Auth\Authentication;
use Hampel\CloseApi\Close as Client;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Resource\Activities;
use Hampel\CloseApi\Resource\Activity\Calls;
use Hampel\CloseApi\Resource\Activity\Emails;
use Hampel\CloseApi\Resource\Activity\Meetings;
use Hampel\CloseApi\Resource\Activity\Messages;
use Hampel\CloseApi\Resource\Activity\Notes;
use Hampel\CloseApi\Resource\Contacts;
use Hampel\CloseApi\Resource\CustomFields;
use Hampel\CloseApi\Resource\CustomFieldType;
use Hampel\CloseApi\Resource\Leads;
use Hampel\CloseApi\Resource\Opportunities;
use Hampel\CloseApi\Resource\Search;
use Hampel\CloseApi\Resource\Statuses;
use Hampel\CloseApi\Resource\Tasks;
use Hampel\CloseApi\Resource\Users;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Close API manager.
 *
 *     Close::leads()->list();                     // the default account
 *     Close::client('sales')->leads()->list();    // a named one
 *
 * The resources are one call further in, so the annotations below type that call and the
 * resource classes type everything after it. Without them, every call through the facade is
 * untyped for both the IDE and PHPStan.
 *
 * Every method after withAuthentication() is a method of the core package's Close class,
 * reached through the manager's __call(). FacadeConformanceTest checks that the two lists
 * match, so if a later release adds a method to Close, a test fails instead of the call
 * quietly losing its type.
 *
 * The core class is imported as Client because it has the same short name as this facade.
 *
 * @method static Client client(?string $name = null)
 * @method static Client withAuthentication(Authentication $auth)
 * @method static string getDefaultAccount()
 * @method static list<string> configuredAccounts()
 * @method static Transport transport()
 * @method static Leads leads()
 * @method static Contacts contacts()
 * @method static Opportunities opportunities()
 * @method static Tasks tasks()
 * @method static Users users()
 * @method static Activities activities()
 * @method static Notes notes()
 * @method static Emails emails()
 * @method static Calls calls()
 * @method static Messages messages()
 * @method static Meetings meetings()
 * @method static CustomFields customFields(CustomFieldType $type)
 * @method static Statuses leadStatuses()
 * @method static Statuses opportunityStatuses()
 * @method static Search search()
 *
 * @see \Hampel\CloseApi\Laravel\CloseManager
 * @see \Hampel\CloseApi\Close
 */
final class Close extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hampel\CloseApi\Laravel\CloseManager::class;
    }
}
