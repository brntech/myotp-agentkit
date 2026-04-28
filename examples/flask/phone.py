"""Phone number sanitiser.

MyOTP requires phone numbers in international format with no leading "+"
or "0" -- digits only. Example: "14155551234". The API rejects anything else
with a 400.
"""

import re

_NON_DIGIT = re.compile(r"\D+")


def sanitise_phone(value: str) -> str:
    return _NON_DIGIT.sub("", value or "").lstrip("0")
