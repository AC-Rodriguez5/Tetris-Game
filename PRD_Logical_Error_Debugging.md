# PRD: Logical Error Debugging Task

## 1. Project Name
Logical Error Debugging and Code Behavior Fix

## 2. Purpose
This PRD defines the requirements for analyzing, identifying, and fixing logical errors in an existing codebase without changing the project structure unnecessarily.

The goal is to make the program behave according to the expected output while keeping the fix simple, readable, and maintainable.

## 3. Problem Statement
The current code runs but produces incorrect behavior, incorrect output, or unexpected results. The issue is assumed to be caused by logical errors such as incorrect conditions, wrong variable values, incorrect loop behavior, missing validation, wrong function flow, or incorrect data handling.

## 4. Objectives
- Understand the intended behavior of the program.
- Trace the actual code execution step by step.
- Identify the exact location of the logical error.
- Explain the reason for the error in simple terms.
- Apply the smallest safe fix possible.
- Preserve the current code structure unless a rewrite is necessary.

## 5. Scope
### Included
- Logical error detection
- Flow analysis
- Condition checking
- Variable and data validation
- Function behavior review
- Minimal code correction
- Basic test cases to verify the fix

## 6. Inputs Required
The developer must provide:

1. Code files or relevant code snippets
2. Description of the issue
3. Expected behavior
4. Actual behavior
5. Error messages, if any
6. Steps to reproduce the problem
7. Sample input and output, if available

## 7. Expected Behavior
The fixed code should produce the correct output or behavior based on the user's requirements.

Example:

When the user performs a specific action, the program should process the data correctly, follow the correct condition, update the correct value, and return the expected result.

## 8. Debugging Process
The debugging process must follow this sequence:

1. Read and understand the code.
2. Identify the main function or flow related to the bug.
3. Trace the data from input to output.
4. Check conditions, loops, variables, and function calls.
5. Locate the logical mismatch.
6. Explain why the current logic fails.
7. Apply a minimal fix.
8. Verify the fix with test cases.
9. Suggest improvements only if necessary.

## 9. Functional Requirements
### FR-001: Analyze Existing Code
The debugger must review the provided code and understand how the program currently works.

### FR-002: Identify Logical Errors
The debugger must identify incorrect logic such as:
- Wrong condition
- Incorrect comparison operator
- Incorrect variable assignment
- Missing return statement
- Wrong loop condition
- Incorrect array or object access
- Incorrect function call order
- Incorrect validation flow

### FR-003: Explain the Cause
The debugger must explain the bug in simple terms so the developer can understand why the logic is incorrect.

### FR-004: Provide a Minimal Fix
The debugger must fix only the affected part of the code unless a larger change is required.

### FR-005: Preserve Existing Structure
The debugger must avoid rewriting the whole project unless the current structure prevents a correct fix.

### FR-006: Provide Verification Steps
The debugger must provide test steps or sample cases to confirm that the fix works.

## 10. Non-Functional Requirements
- Code must remain readable.
- Fixes must be maintainable.
- The solution must avoid unnecessary complexity.
- The explanation must be clear for beginner to intermediate developers.
- The fix must not introduce unrelated behavior changes.

## 11. Acceptance Criteria
The debugging task is complete when:

- The logical error is clearly identified.
- The cause of the bug is explained.
- The corrected code is provided.
- The fix matches the expected behavior.
- The code still follows the original project structure.
- Test cases or verification steps are included.

## 12. Claude Code Prompt

Use this prompt in Claude Code:

```text
Act as a senior software debugger. I need you to debug logical errors in my code.

Read the code carefully and focus only on logic problems.

Follow this process:
1. Understand the expected behavior.
2. Trace the actual code flow step by step.
3. Identify where the logic becomes incorrect.
4. Explain the exact cause of the bug in simple terms.
5. Fix the code with minimal changes.
6. Do not rewrite the whole project unless necessary.
7. Preserve the current file structure.
8. Show the corrected code.
9. Explain what was changed.
10. Provide simple test cases to verify the fix.

## 13. Notes
The main priority is to fix the logical error accurately without making unnecessary changes to the project.
