"""Turn JUnit XML test failures into GitHub Actions error annotations.

Usage: python3 junit-annotations.py <junit.xml> [path-prefix]
Prints one ::error line per failed or errored test case (max 30).
"""

import sys
import xml.etree.ElementTree as ET


def escape(text: str) -> str:
    return text.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


def main() -> int:
    path = sys.argv[1]
    prefix = sys.argv[2] if len(sys.argv) > 2 else ""
    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError) as exc:
        print(f"::error title=JUnit report::could not read {path}: {exc}")
        return 0

    shown = 0
    for case in root.iter("testcase"):
        for kind in ("failure", "error"):
            node = case.find(kind)
            if node is None:
                continue
            name = f"{case.get('class') or case.get('classname') or ''} > {case.get('name')}"
            body = (node.get("message") or "") + "\n" + (node.text or "")
            body = body.strip()[:1800]
            file_attr = case.get("file", "")
            location = f"file={prefix}{file_attr.split('::')[0]}," if file_attr else ""
            print(f"::error {location}title={escape(name)[:200]}::{escape(body)}")
            shown += 1
            if shown >= 30:
                return 0
    return 0


if __name__ == "__main__":
    sys.exit(main())
