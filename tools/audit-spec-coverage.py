#!/usr/bin/env python3
"""Inventory Spec coverage and compare TC39 cases without ignoring incomplete tests."""

import argparse
import hashlib
import json
from pathlib import Path
import xml.etree.ElementTree as ET


def corpus_digest(root):
    if not root.is_dir():
        raise ValueError(f"Missing fixture directory: {root}")
    digest = hashlib.sha256()
    for path in sorted(root.rglob("*")):
        if path.is_file():
            digest.update(str(path.relative_to(root)).encode())
            digest.update(b"\0")
            digest.update(hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()


def snapshot(clover, junit):
    files = {}
    for file in ET.parse(clover).iter("file"):
        name = file.attrib["name"]
        if "/src/Spec/" not in name:
            continue
        name = "src/Spec/" + name.split("/src/Spec/", 1)[1]
        statements = [line for line in file.findall("line") if line.get("type") == "stmt"]
        files[name] = {
            "executable": len(statements),
            "missed": sorted(int(line.get("num")) for line in statements if int(line.get("count")) == 0),
        }
    cases = {}
    for case in ET.parse(junit).iter("testcase"):
        key = case.get("classname", "") + "::" + case.attrib["name"]
        if key in cases:
            raise ValueError(f"Duplicate test case: {key}")
        outcomes = sorted(child.tag for child in case if child.tag in {"failure", "error", "skipped"})
        cases[key] = {"assertions": int(case.get("assertions", "0")), "outcomes": outcomes}
    if not cases or not files:
        raise ValueError("The reports must contain test cases and Spec coverage")
    return {
        "fixtures": {str(root): corpus_digest(root) for root in (Path("tests/Test262/data"), Path("tests/Test262/scripts"))},
        "cases": cases,
        "files": files,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("clover", type=Path)
    parser.add_argument("junit", type=Path)
    parser.add_argument("--baseline", type=Path, help="Compare against a previously saved snapshot")
    parser.add_argument("--save", type=Path, help="Save this snapshot")
    args = parser.parse_args()
    if args.save and args.baseline and args.save.resolve() == args.baseline.resolve():
        parser.error("--save must not overwrite --baseline")
    current = snapshot(args.clover, args.junit)
    if args.save:
        args.save.write_text(json.dumps(current, indent=2, sort_keys=True) + "\n")
    executable = sum(file["executable"] for file in current["files"].values())
    missed = sum(len(file["missed"]) for file in current["files"].values())
    print(f"Spec lines: {executable - missed}/{executable}; missed: {missed}")
    print(f"Cases: {len(current['cases'])}; assertions: {sum(case['assertions'] for case in current['cases'].values())}")
    if args.baseline:
        baseline = json.loads(args.baseline.read_text())
        problems = []
        if baseline["fixtures"] != current["fixtures"]:
            problems.append("TC39 fixture contents changed")
        for name in sorted(baseline["cases"].keys() | current["cases"].keys()):
            if baseline["cases"].get(name) != current["cases"].get(name):
                problems.append(f"Case changed: {name}")
        if problems:
            raise SystemExit("\n".join(problems))
        print("Exact case inventory, assertion counts, outcomes, and fixture hashes match baseline.")


if __name__ == "__main__":
    main()
