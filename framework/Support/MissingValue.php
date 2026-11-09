<?php
declare(strict_types=1);

namespace Framework\Support;

/**
 * Marks a field as "omit this key entirely".
 *
 * Why a sentinel rather than `unset()` or a null
 *
 * The alternative designs both lose information that matters:
 *
 *  - **`null`** is a real value. A resource maps `'deleted_at' => null` to say
 *    "this record has not been deleted", and a client distinguishing that from
 *    "this field is not applicable to this record" needs two different answers.
 *    Using null for both makes them indistinguishable.
 *  - **`unset()` inside `toArray()`** would work, but it puts the omission and
 *    the mapping in different statements - a reader scanning `toArray()` sees a
 *    field being set and has to hunt for the one that removes it.
 *
 * A marker value keeps the mapping declarative: a field is either there, or it is
 * this, and the resolution step drops it. Nothing is filtered by name-matching a
 * `null` later, which is the version of this that silently eats a legitimate null.
 *
 * Why it is a class and not a constant
 *
 * `JsonResource::when()` returns `new MissingValue()` - an object, so the check
 * in `prune()` is an `instanceof` and cannot be satisfied by any value a resource
 * author might legitimately produce. A string sentinel like `'__missing__'` is one
 * careless `toArray()` away from being emitted as an actual field value.
 */
final class MissingValue
{
}
