// Все декораторы авторизации в одном файле.
//
//   @Public()            — роут доступен БЕЗ токена (логин, регистрация и т.п.).
//                          По умолчанию ВСЕ роуты закрыты (см. auth.guard.ts).
//   @Roles(Role.ADMIN)   — роут доступен только пользователям с одной из ролей.
//                          Без @Roles достаточно просто быть залогиненным.
//   @CurrentUser()       — достать текущего пользователя в параметр метода.
//
// Пример:
//   @Roles(Role.ADMIN)
//   @Get('admin-only')
//   foo(@CurrentUser() user: User) { ... }
//
// @Public и @Roles можно вешать и на метод, и на весь контроллер.

import {
  createParamDecorator,
  ExecutionContext,
  SetMetadata,
} from '@nestjs/common';
import { Role, User } from '../user/user.entity';

// Ключи метаданных — по ним guard читает, что повесили на роут.
export const IS_PUBLIC_KEY = 'isPublic';
export const ROLES_KEY = 'roles';

export const Public = () => SetMetadata(IS_PUBLIC_KEY, true);

export const Roles = (...roles: Role[]) => SetMetadata(ROLES_KEY, roles);

// Guard кладёт пользователя в request.user, а этот декоратор его оттуда достаёт.
// @CurrentUser('id') вернёт только поле id.
export const CurrentUser = createParamDecorator(
  (field: keyof User | undefined, ctx: ExecutionContext) => {
    const user = ctx.switchToHttp().getRequest<{ user?: User }>().user;
    return field ? user?.[field] : user;
  },
);
