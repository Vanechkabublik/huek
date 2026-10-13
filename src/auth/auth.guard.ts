// Единственный guard авторизации. Зарегистрирован ГЛОБАЛЬНО (APP_GUARD в auth.module.ts),
// поэтому срабатывает на каждом запросе ко всем контроллерам приложения.
//
// Порядок проверки:
//   1. Роут помечен @Public()      → пропускаем без токена.
//   2. Достаём токен из cookie `access_token` (или из заголовка Authorization: Bearer ...).
//   3. Проверяем подпись и срок жизни токена.
//   4. Достаём пользователя из БД по id из токена (если удалён — 401)
//      и кладём его в request.user — оттуда его берёт @CurrentUser().
//   5. Если на роуте есть @Roles(...) — роль пользователя должна быть в списке, иначе 403.
//
// Passport тут не нужен: JwtService из @nestjs/jwt умеет всё сам.

import {
  CanActivate,
  ExecutionContext,
  ForbiddenException,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { JwtService } from '@nestjs/jwt';
import { InjectRepository } from '@nestjs/typeorm';
import type { Request } from 'express';
import { Repository } from 'typeorm';

import { Role, User } from '../user/user.entity';
import { IS_PUBLIC_KEY, ROLES_KEY } from './auth.decorators';
import { ACCESS_COOKIE, JwtPayload } from './auth.service';

@Injectable()
export class AuthGuard implements CanActivate {
  constructor(
    private readonly reflector: Reflector,
    private readonly jwt: JwtService,
    @InjectRepository(User) private readonly users: Repository<User>,
  ) {}

  async canActivate(ctx: ExecutionContext): Promise<boolean> {
    // getAllAndOverride: сначала смотрим метаданные метода, потом контроллера.
    const targets = [ctx.getHandler(), ctx.getClass()];

    if (this.reflector.getAllAndOverride<boolean>(IS_PUBLIC_KEY, targets)) {
      return true;
    }

    const req = ctx.switchToHttp().getRequest<Request & { user?: User }>();
    const token = this.extractToken(req);
    if (!token) throw new UnauthorizedException('Нет токена');

    let payload: JwtPayload;
    try {
      // Секрет берётся из JwtModule (auth.module.ts). Протухший/поддельный токен → исключение.
      payload = await this.jwt.verifyAsync<JwtPayload>(token);
    } catch {
      throw new UnauthorizedException('Невалидный или истёкший токен');
    }

    // Берём юзера из БД, а не из токена: так смена роли или удаление
    // пользователя действуют сразу, а не после истечения токена.
    const user = await this.users.findOneBy({ id: payload.sub });
    if (!user) throw new UnauthorizedException('Пользователь не найден');
    req.user = user;

    const roles = this.reflector.getAllAndOverride<Role[]>(ROLES_KEY, targets);
    if (roles?.length && !roles.includes(user.role)) {
      throw new ForbiddenException('Недостаточно прав');
    }

    return true;
  }

  // Основной способ — httpOnly cookie (её ставит AuthService при логине).
  // Заголовок Authorization: Bearer — запасной, удобно для Postman/Swagger/мобилок.
  private extractToken(req: Request): string | undefined {
    const fromCookie = req.cookies?.[ACCESS_COOKIE] as string | undefined;
    if (fromCookie) return fromCookie;

    const [type, value] = req.headers.authorization?.split(' ') ?? [];
    return type === 'Bearer' ? value : undefined;
  }
}
